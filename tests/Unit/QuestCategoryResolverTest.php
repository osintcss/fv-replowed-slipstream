<?php

namespace Tests\Unit;

use App\Support\QuestCategoryResolver;
use PHPUnit\Framework\TestCase;

final class QuestCategoryResolverTest extends TestCase
{
    public function test_it_maps_verified_legacy_quest_categories(): void
    {
        self::assertContains('Peanut', QuestCategoryResolver::categories('peanuts', ['subtype' => 'misc']));
        self::assertContains('dinoLab', QuestCategoryResolver::categories('animal_breeding_dinolab_finished'));
        self::assertContains('petRunHabitat', QuestCategoryResolver::categories('animal_breeding_petrun_finished'));
    }

    public function test_it_normalizes_all_prefixed_task_categories(): void
    {
        self::assertSame('Peanut', QuestCategoryResolver::taskCategory('allPeanut'));
        self::assertSame('dinolab', QuestCategoryResolver::normalized('dinoLab'));
    }
}
