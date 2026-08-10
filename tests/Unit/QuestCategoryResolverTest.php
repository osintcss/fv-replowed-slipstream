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
        self::assertContains('BellPeppers', QuestCategoryResolver::categories('bellpepperyellow'));
        self::assertContains('DarrowBlackberry', QuestCategoryResolver::categories('blackberriesdarrow'));
        self::assertContains('Pineapple', QuestCategoryResolver::categories('pineapples'));
        self::assertContains('RedTulips', QuestCategoryResolver::categories('tulipred'));
        self::assertContains('aviaryHabitat', QuestCategoryResolver::categories('animal_breeding_aviary_finished'));
        self::assertContains('animalBreedingAll', QuestCategoryResolver::categories('animal_breeding_aviary_finished'));
    }

    public function test_it_normalizes_all_prefixed_task_categories(): void
    {
        self::assertSame('Peanut', QuestCategoryResolver::taskCategory('allPeanut'));
        self::assertSame('dinolab', QuestCategoryResolver::normalized('dinoLab'));
    }
}
