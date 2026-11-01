<?php

declare(strict_types=1);

/**
 * One "Gruppenmanagement" inside a GroupManagement repository object.
 *
 * Each management uses exactly one method (a–j). Per repository object only
 * one management can be active at the same time.
 */
class ilGrmManagement
{
    /** Configured, but no assignment has been drawn yet */
    public const STATUS_DRAFT = "draft";
    /** Assignment has been drawn, visible for lecturers only */
    public const STATUS_DRAWN = "drawn";
    /** "Go" has been pressed: the assignment is visible for the students */
    public const STATUS_PUBLISHED = "published";

    /** Method a: fixed number of groups */
    public const MODE_GROUPS = "groups";
    /** Method a: fixed number of topics (one group per topic) */
    public const MODE_TOPICS = "topics";

    public const MAX_GROUPS = 500;

    private int $id = 0;
    private int $obj_id;
    private string $method;
    private string $title = "";
    private bool $active = false;
    private string $status = self::STATUS_DRAFT;
    private string $assign_mode = self::MODE_GROUPS;
    private int $num_groups = 2;
    /** @var string[] */
    private array $topics = [];
    private bool $show_all_groups = true;
    private int $created = 0;
    private ?int $published = null;

    public function __construct(int $obj_id, string $method)
    {
        $this->obj_id = $obj_id;
        $this->method = $method;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getObjId(): int
    {
        return $this->obj_id;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDrawn(): bool
    {
        return $this->status === self::STATUS_DRAWN || $this->status === self::STATUS_PUBLISHED;
    }

    public function getAssignMode(): string
    {
        return $this->assign_mode;
    }

    public function setAssignMode(string $assign_mode): void
    {
        $this->assign_mode = $assign_mode === self::MODE_TOPICS ? self::MODE_TOPICS : self::MODE_GROUPS;
    }

    public function getNumGroups(): int
    {
        return $this->num_groups;
    }

    public function setNumGroups(int $num_groups): void
    {
        $this->num_groups = max(1, min(self::MAX_GROUPS, $num_groups));
    }

    /**
     * @return string[]
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * @param string[] $topics
     */
    public function setTopics(array $topics): void
    {
        $this->topics = array_slice(self::cleanTopics($topics), 0, self::MAX_GROUPS);
    }

    public function isShowAllGroups(): bool
    {
        return $this->show_all_groups;
    }

    public function setShowAllGroups(bool $show_all_groups): void
    {
        $this->show_all_groups = $show_all_groups;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): void
    {
        $this->created = $created;
    }

    public function getPublished(): ?int
    {
        return $this->published;
    }

    public function setPublished(?int $published): void
    {
        $this->published = $published;
    }

    /**
     * Number of groups that will be created by a draw.
     */
    public function getEffectiveNumberOfGroups(): int
    {
        return $this->assign_mode === self::MODE_TOPICS ? count($this->topics) : $this->num_groups;
    }

    /**
     * Titles of the groups that will be created by a draw.
     *
     * @param callable(int):string $group_title_builder builds the title of
     *        group n (1-based) in "fixed number of groups" mode
     * @return string[]
     */
    public function buildGroupTitles(callable $group_title_builder): array
    {
        if ($this->assign_mode === self::MODE_TOPICS) {
            return $this->topics;
        }
        $titles = [];
        for ($i = 1; $i <= $this->num_groups; $i++) {
            $titles[] = $group_title_builder($i);
        }
        return $titles;
    }

    /**
     * Splits a multi-line text into topics (one topic per non-empty line).
     *
     * @return string[]
     */
    public static function parseTopics(string $text): array
    {
        return self::cleanTopics(preg_split('/\R/u', $text) ?: []);
    }

    /**
     * @param string[] $topics
     * @return string[]
     */
    private static function cleanTopics(array $topics): array
    {
        $clean = [];
        foreach ($topics as $topic) {
            $topic = trim((string) $topic);
            if ($topic !== "") {
                $clean[] = mb_substr($topic, 0, 255);
            }
        }
        return $clean;
    }
}
