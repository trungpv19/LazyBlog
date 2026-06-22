<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;

/**
 * Build a valid RSS 2.0 feed XML for the latest published posts.
 *
 * Uses DOMDocument (not string concatenation) so all titles, summaries, and
 * body HTML are properly XML-escaped or CDATA-wrapped — no risk of producing
 * malformed feeds when a post contains < > & " etc.
 *
 * The rendered HTML body is included as <content:encoded> wrapped in CDATA,
 * matching the convention most readers (Feedly, NetNewsWire, Reeder) expect.
 */
final class FeedBuilder
{
    /** Max items in the feed — RSS readers don't need the full history. */
    private const ITEM_LIMIT = 20;

    public function __construct(
        private readonly PostRepository $repo,
        private readonly MarkdownRenderer $renderer,
        private readonly string $contentDir,
    ) {
    }

    public function cachePath(): string
    {
        return $this->contentDir . '/.feed.xml';
    }

    /**
     * Return the feed XML, building + caching it if missing.
     */
    public function readOrBuild(): string
    {
        $path = $this->cachePath();
        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }
        $xml = $this->build();
        @file_put_contents($path, $xml, LOCK_EX);
        return $xml;
    }

    public function build(): string
    {
        $siteTitle = (string) Config::get('SITE_TITLE');
        $siteUrl = rtrim((string) Config::get('SITE_URL'), '/');
        $siteDesc = (string) Config::get('SITE_DESCRIPTION', '');
        $tz = new \DateTimeZone((string) Config::get('TIMEZONE', 'UTC'));

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $dom->appendChild($rss);

        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);

        // ---------- channel metadata ----------
        $this->appendText($dom, $channel, 'title', $siteTitle);
        $this->appendText($dom, $channel, 'link', $siteUrl . '/');
        $this->appendText($dom, $channel, 'description', $siteDesc);
        $this->appendText($dom, $channel, 'language', 'vi');
        $this->appendText(
            $dom,
            $channel,
            'lastBuildDate',
            (new \DateTimeImmutable('now', $tz))->format(\DateTimeInterface::RFC2822),
        );
        $this->appendText($dom, $channel, 'generator', 'LazyBlog (PHP + Markdown)');

        // <atom:link rel="self"> — convention, tells readers the canonical feed URL
        $self = $dom->createElement('atom:link');
        $self->setAttribute('href', $siteUrl . '/feed.xml');
        $self->setAttribute('rel', 'self');
        $self->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($self);

        // ---------- items ----------
        $entries = array_slice($this->repo->published(), 0, self::ITEM_LIMIT);
        foreach ($entries as $entry) {
            $post = $this->repo->bySlug($entry['slug']);
            if ($post === null) {
                continue;
            }
            $this->appendItem($dom, $channel, $post, $siteUrl, $tz);
        }

        $xml = $dom->saveXML();
        return $xml === false ? '<?xml version="1.0" encoding="UTF-8"?><rss/>' : $xml;
    }

    private function appendItem(
        DOMDocument $dom,
        DOMElement $channel,
        Post $post,
        string $siteUrl,
        \DateTimeZone $tz,
    ): void {
        $item = $dom->createElement('item');
        $channel->appendChild($item);

        $url = $siteUrl . $post->url();

        $this->appendText($dom, $item, 'title', $post->title);
        $this->appendText($dom, $item, 'link', $url);

        $guid = $dom->createElement('guid');
        $guid->setAttribute('isPermaLink', 'true');
        $guid->appendChild($dom->createTextNode($url));
        $item->appendChild($guid);

        try {
            $dt = new \DateTimeImmutable($post->date, $tz);
            $this->appendText($dom, $item, 'pubDate', $dt->format(\DateTimeInterface::RFC2822));
        } catch (\Throwable) {
            // Skip pubDate on bad date; the feed is still valid without it.
        }

        if ($post->author !== null && $post->author !== '') {
            $this->appendText($dom, $item, 'dc:creator', $post->author);
        }

        foreach ($post->tags as $tag) {
            $this->appendText($dom, $item, 'category', $tag);
        }

        // description = excerpt (plain-text safe, what readers preview)
        $this->appendText($dom, $item, 'description', $post->excerpt(280));

        // content:encoded = full rendered HTML, CDATA-wrapped for safety.
        $rendered = $this->renderer->render($post->bodyMarkdown);
        $content = $dom->createElement('content:encoded');
        $content->appendChild($dom->createCDATASection($rendered['html']));
        $item->appendChild($content);
    }

    private function appendText(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        // createElement() auto-escapes the value via appendChild(createTextNode()).
        $el = $dom->createElement($name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
    }
}
