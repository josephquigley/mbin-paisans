<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\Entity\Magazine;
use App\Entity\User;
use App\Service\ActivityPubManager;
use App\Service\MentionManager;

class ApObjectExtractor
{
    public const MARKDOWN_TYPE = 'text/markdown';

    public function __construct(
        private readonly MarkdownConverter $markdownConverter,
        private readonly ActivityPubManager $activityPubManager,
        private readonly MentionManager $mentionManager,
    ) {
    }

    /**
     * Extract the mentions an object addresses in its `tag` array.
     *
     * The body is not the only place a mention can live. An object addresses
     * an actor by carrying a Mention tag for it, and whether the handle also
     * appears in the content is a rendering choice of the sending software.
     * Mastodon and GoToSocial both send mentions that appear in no text, and
     * Mbin does the same: MentionManager::handleChain adds the parent's
     * mentions and the parent's author to a reply, and MentionsWrapper::build
     * emits a Mention tag for each of them whether or not the author typed
     * one.
     *
     * MarkdownConverter reads the same tag array, but only to resolve a link
     * that the content already contains, so a mention named nowhere in the
     * body is lost there.
     *
     * A tag whose actor cannot be resolved is skipped: an address nothing can
     * be delivered to is of no use to a reply that would inherit it.
     *
     * @param array<string, mixed> $object
     *
     * @return string[]|null
     */
    public function getMentions(array $object): ?array
    {
        $tags = $object['tag'] ?? [];
        if (!\is_array($tags)) {
            return null;
        }

        $mentions = [];
        foreach ($tags as $tag) {
            if (!\is_array($tag) || 'Mention' !== ($tag['type'] ?? null) || empty($tag['href'])) {
                continue;
            }

            try {
                $actor = $this->activityPubManager->findActorOrCreate($tag['href']);
            } catch (\Throwable) {
                continue;
            }

            if ($actor instanceof User) {
                $mentions[] = $this->mentionManager->getUsername($actor->username, true);
            } elseif ($actor instanceof Magazine) {
                $mentions[] = $this->mentionManager->getUsername('@'.$actor->name, true);
            }
        }

        return \count($mentions) ? array_values(array_unique($mentions)) : null;
    }

    public function getMarkdownBody(array $object): ?string
    {
        $content = $object['content'] ?? null;
        $source = $object['source'] ?? null;

        // object has no content nor source to extract body from
        if (null === $content && null === $source) {
            return null;
        }

        if ($source && (isset($source['mediaType']) && self::MARKDOWN_TYPE === $source['mediaType'])) {
            // markdown source found, return them
            return $source['content'] ?? null;
        } elseif ($content && (isset($object['mediaType']) && self::MARKDOWN_TYPE === $object['mediaType'])) {
            // markdown source isn't found but object's content is specified
            // to be markdown, also return them
            return $content;
        } elseif ($content && \is_string($content)) {
            // assuming default content mediaType of text/html,
            // returning html -> markdown conversion of content
            return $this->markdownConverter->convert($content, $object['tag'] ?? []);
        }

        return '';
    }

    public function getExternalMediaBody(array $object): ?string
    {
        $body = null;

        if (isset($object['attachment'])) {
            $attachments = $object['attachment'];

            if ($images = $this->activityPubManager->handleExternalImages($attachments)) {
                $body .= "\n\n".implode(
                    "  \n",
                    array_map(
                        fn ($image) => \sprintf(
                            '![%s](%s)',
                            preg_replace('/\r\n|\r|\n/', ' ', $image->name),
                            $image->url
                        ),
                        $images
                    )
                );
            }

            if ($videos = $this->activityPubManager->handleExternalVideos($attachments)) {
                $body .= "\n\n".implode(
                    "  \n",
                    array_map(
                        fn ($video) => \sprintf(
                            '![%s](%s)',
                            preg_replace('/\r\n|\r|\n/', ' ', $video->name),
                            $video->url
                        ),
                        $videos
                    )
                );
            }
        }

        return $body;
    }
}
