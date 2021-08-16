<?php
namespace OCA\Recognize\Service;


use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;

class TagManager
{
    public const RECOGNIZED_TAG = 'Tagged by recognize';

    /**
     * @var \OCP\SystemTag\ISystemTagManager
     */
    private $tagManager;
    /**
     * @var \OCP\SystemTag\ISystemTagObjectMapper
     */
    private $objectMapper;

    public function __construct(ISystemTagManager $systemTagManager, ISystemTagObjectMapper $objectMapper)
    {
        $this->tagManager = $systemTagManager;
        $this->objectMapper = $objectMapper;
    }

    public function getTag($name) : ISystemTag {
        try {
            $tag = $this->tagManager->getTag($name, true, true);
        }catch(TagNotFoundException $e) {
            $tag = $this->tagManager->createTag($name, true, true);
        }
        return $tag;
    }

    public function getProcessedTag(): ISystemTag
    {
        try {
            $tag = $this->tagManager->getTag(self::RECOGNIZED_TAG, false, false);
        }catch(TagNotFoundException $e) {
            $tag = $this->tagManager->createTag(self::RECOGNIZED_TAG, false, false);
        }
        return $tag;
    }

    public function assignTags(int $fileId, array $tags): void
    {
        $tags = array_map(function ($tag) {
            return $this->getTag($tag)->getId();
        }, $tags);
        $tags[] = $this->getProcessedTag()->getId();
        $this->objectMapper->assignTags($fileId, 'files', $tags);
    }

    public function findClassifiedFiles(): array
    {
        return $this->objectMapper->getObjectIdsForTags($this->getProcessedTag()->getId(), 'files');
    }

    public function findMissedClassifications(): array
    {
        $missed = array_keys(array_filter($this->objectMapper->getTagIdsForObjects($this->findClassifiedFiles(), 'files'), function($tags) {
            $tags = $this->tagManager->getTagsByIds($tags);
            return count(array_filter($tags, static function ($tag) {
                return $tag->getName() !== 'Unrecognized' && $tag->getName() !== self::RECOGNIZED_TAG;
            })) === 0;
        }));
        $unrecognized = $this->objectMapper->getObjectIdsForTags($this->getTag('Unrecognized')->getId(), 'files');
        $notMissedAnymore = array_diff($unrecognized, $missed);

        foreach($notMissedAnymore as $id) {
            $this->objectMapper->unassignTags($id, 'files', [$this->getTag('Unrecognized')->getId()]);
        }
        foreach($missed as $id) {
            $this->objectMapper->assignTags($id, 'files', [$this->getTag('Unrecognized')->getId()]);
        }
        return $missed;
    }

    public function resetClassifications(): void
    {
        $fileIds = $this->findClassifiedFiles();
        foreach($fileIds as $id) {
            $tagIds = $this->objectMapper->getTagIdsForObjects($id, 'files');
            $this->objectMapper->unassignTags($id, 'files', $tagIds[$id]);
        }
    }
}
