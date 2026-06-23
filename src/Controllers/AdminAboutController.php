<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AboutPage;
use App\AboutRepository;
use App\Auth;
use App\Csrf;
use App\Http;

/**
 * Admin CRUD for the about page.
 *   GET  /admin/about      — render form (pre-filled when about.md exists)
 *   POST /admin/about/save — write content/about.md atomically
 *
 * Auth + CSRF gate the same way the post editor does. Contacts use a
 * pipe-separated textarea ("LABEL | value | url") instead of a dynamic
 * row UI — keeps the form a single <textarea> the admin can edit even
 * without JS.
 */
final class AdminAboutController
{
    public function __construct(private readonly AboutRepository $repo)
    {
    }

    public function editForm(): void
    {
        Auth::requireAuth();

        $about = $this->repo->get();
        $values = self::pageToFormValues($about);

        Http::render('admin/edit-about', [
            'title' => $about === null ? 'Create About' : 'Edit About',
            'isNew' => $about === null,
            'formError' => null,
            'formValues' => $values,
        ]);
    }

    public function save(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $values = self::readFormValues();
        $wasNew = !$this->repo->exists();

        try {
            $page = self::buildPageFromForm($values);
            $this->repo->save($page);
        } catch (\Throwable $e) {
            http_response_code(400);
            Http::render('admin/edit-about', [
                'title' => $wasNew ? 'Create About' : 'Edit About',
                'isNew' => $wasNew,
                'formError' => $e->getMessage(),
                'formValues' => $values,
            ]);
            return;
        }

        Http::redirect('/about');
    }

    /**
     * @return array{name:string,callsign:string,location:string,status:string,avatar:string,contacts:string,stack:string,currently:string,body:string}
     */
    private static function readFormValues(): array
    {
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'callsign' => trim((string) ($_POST['callsign'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? '')),
            'avatar' => trim((string) ($_POST['avatar'] ?? '')),
            'contacts' => (string) ($_POST['contacts'] ?? ''),
            'stack' => (string) ($_POST['stack'] ?? ''),
            'currently' => trim((string) ($_POST['currently'] ?? '')),
            'body' => (string) ($_POST['body'] ?? ''),
        ];
    }

    /**
     * Flatten an AboutPage back into form values so editForm() pre-fills
     * the inputs. Contacts collapse to one row per line: "LABEL | value | url".
     * Stack collapses to a comma-separated single line.
     *
     * @return array{name:string,callsign:string,location:string,status:string,avatar:string,contacts:string,stack:string,currently:string,body:string}
     */
    private static function pageToFormValues(?AboutPage $page): array
    {
        if ($page === null) {
            return [
                'name' => '',
                'callsign' => '',
                'location' => '',
                'status' => '',
                'avatar' => '',
                'contacts' => '',
                'stack' => '',
                'currently' => '',
                'body' => '',
            ];
        }
        $contactsText = '';
        foreach ($page->contacts as $c) {
            $line = $c['label'] . ' | ' . $c['value'];
            if (($c['url'] ?? null) !== null && $c['url'] !== '') {
                $line .= ' | ' . $c['url'];
            }
            $contactsText .= $line . "\n";
        }
        return [
            'name' => $page->name,
            'callsign' => (string) $page->callsign,
            'location' => (string) $page->location,
            'status' => (string) $page->status,
            'avatar' => (string) $page->avatar,
            'contacts' => rtrim($contactsText, "\n"),
            'stack' => implode(', ', $page->stack),
            'currently' => (string) $page->currently,
            'body' => $page->bodyMarkdown,
        ];
    }

    /**
     * @param array{name:string,callsign:string,location:string,status:string,avatar:string,contacts:string,stack:string,currently:string,body:string} $v
     */
    private static function buildPageFromForm(array $v): AboutPage
    {
        if ($v['name'] === '') {
            throw new \RuntimeException('Name is required.');
        }

        $contacts = [];
        foreach (preg_split("/\r?\n/", $v['contacts']) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            // "LABEL | value | url" — url is optional. Extra pipes inside
            // value/url are rare enough that splitting on first 3 segments
            // covers every realistic case.
            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $contacts[] = [
                'label' => $parts[0],
                'value' => $parts[1],
                'url' => isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null,
            ];
        }

        // Stack: comma-separated chips, trimmed + emptied-dropped.
        $stack = [];
        foreach (explode(',', $v['stack']) as $chunk) {
            $chip = trim($chunk);
            if ($chip !== '') {
                $stack[] = $chip;
            }
        }

        return new AboutPage(
            name: $v['name'],
            callsign: $v['callsign'] !== '' ? $v['callsign'] : null,
            location: $v['location'] !== '' ? $v['location'] : null,
            status: $v['status'] !== '' ? $v['status'] : null,
            avatar: $v['avatar'] !== '' ? $v['avatar'] : null,
            contacts: $contacts,
            stack: $stack,
            currently: $v['currently'] !== '' ? $v['currently'] : null,
            bodyMarkdown: $v['body'],
        );
    }
}
