<?php

namespace App\Observers;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Redirect;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use App\Support\Content\ContentNormalizer;
use App\Support\Content\InvalidContentException;
use App\Support\Links\LinkRegistry;
use Illuminate\Validation\ValidationException;

class PostObserver
{
    public function saving(Post $post): void
    {
        $this->guardPublishing($post);
        $this->normalizeContent($post);

        if ($post->status === PostStatus::Published && $post->published_at === null) {
            $post->published_at = now();
        }
    }

    public function updated(Post $post): void
    {
        $this->redirectOldSlug($post);
    }

    /** Puts the outside links of a live post on the checking list. */
    public function saved(Post $post): void
    {
        if ($post->status === PostStatus::Published
            && ($post->wasRecentlyCreated || $post->wasChanged(['content', 'status']))) {
            app(LinkRegistry::class)->register($post);
        }
    }

    /**
     * Only users holding the "publish" ability may publish, schedule or
     * unpublish. The admin form disables those controls for editors, but a
     * tampered request must not get around it. With nobody signed in
     * (seeders, console commands) there is nothing to check.
     */
    private function guardPublishing(Post $post): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $touchesPublishing = $post->exists
            ? $post->isDirty('status') || $post->isDirty('published_at')
            : $post->status !== PostStatus::Draft || $post->published_at !== null;

        if ($touchesPublishing && ! $user->can('publish', $post)) {
            throw new AuthorizationException('Only admins can publish, schedule or unpublish posts.');
        }
    }

    /**
     * Cleans the editor's content, records the reading time and makes sure live
     * or scheduled posts are not empty. The admin form checks the same rules
     * earlier; this is the backstop for every other way of saving.
     */
    private function normalizeContent(Post $post): void
    {
        if (! $post->exists || $post->isDirty('content')) {
            try {
                $result = app(ContentNormalizer::class)->normalize($post->content);
            } catch (InvalidContentException $e) {
                throw ValidationException::withMessages($this->contentErrors($e->messages()));
            }

            $post->content = $result->isEmpty ? null : $result->document;
            $post->reading_time_minutes = $result->readingTimeMinutes();
            $isEmpty = $result->isEmpty;
        } else {
            $isEmpty = empty($post->content);
        }

        $needsContent = in_array($post->status, [PostStatus::Published, PostStatus::Scheduled], true);

        if ($needsContent && $isEmpty) {
            throw ValidationException::withMessages(
                $this->contentErrors(['Published and scheduled posts need some content.'])
            );
        }
    }

    /**
     * @param  list<string>  $messages
     * @return array<string, list<string>>
     */
    private function contentErrors(array $messages): array
    {
        // "data.content" is the key the admin form shows under the editor.
        return ['content' => $messages, 'data.content' => $messages];
    }

    /**
     * Changing the slug of a live post keeps the old URL working with a 301,
     * without ever creating redirect chains or loops.
     */
    private function redirectOldSlug(Post $post): void
    {
        if (! $post->wasChanged('slug') || $post->getOriginal('status') !== PostStatus::Published) {
            return;
        }

        $prefix = rtrim((string) config('azhayon.paths.post'), '/');
        $old = $prefix.'/'.$post->getOriginal('slug');
        $new = $prefix.'/'.$post->slug;

        DB::transaction(function () use ($old, $new): void {
            // The new URL is live now, so it must never redirect away (prevents loops).
            Redirect::where('from_path', $new)->delete();

            // Point anything that targeted the old URL straight at the new one (no chains).
            Redirect::where('to_path', $old)->update(['to_path' => $new]);

            Redirect::updateOrCreate(
                ['from_path' => $old],
                ['to_path' => $new, 'status_code' => 301],
            );
        });
    }
}