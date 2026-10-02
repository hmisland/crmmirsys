<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Repositories\TagRepository;
use App\Infrastructure\Validation\Validator;
use App\Core\Exceptions\ValidationException;

final class TagService
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly TimelineService $timeline,
    ) {
    }

    public function allForOrganization(): array
    {
        return $this->tags->all();
    }

    public function forSubject(string $type, int $id): array
    {
        return $this->tags->forSubject($type, $id);
    }

    public function attach(string $type, int $id, string $tagName, int $actorUserId): void
    {
        $validator = Validator::make(['name' => $tagName], ['name' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $tag = $this->tags->findOrCreate(trim($tagName));
        $this->tags->attach((int) $tag['id'], $type, $id);
        $this->timeline->record($type, $id, 'tag_added', "Tagged \"{$tag['name']}\"", $actorUserId);
    }

    public function detach(string $type, int $id, int $tagId, int $actorUserId): void
    {
        $tag = $this->tags->findById($tagId);
        $this->tags->detach($tagId, $type, $id);

        if ($tag !== null) {
            $this->timeline->record($type, $id, 'tag_removed', "Removed tag \"{$tag['name']}\"", $actorUserId);
        }
    }

    /**
     * Every subject id of one type carrying this exact tag name — the
     * "search by tag" the File Library uses. Returns [] for a tag
     * name that doesn't exist in this org rather than erroring; an
     * unmatched search term is a normal, expected outcome here.
     *
     * @return int[]
     */
    public function subjectIdsWithTagName(string $type, string $tagName): array
    {
        $tag = $this->tags->findByName(trim($tagName));

        if ($tag === null) {
            return [];
        }

        return $this->tags->subjectIdsWithTag($type, (int) $tag['id']);
    }
}
