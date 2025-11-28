<?php

namespace Tests\Unit;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Question;
use App\Models\QuestionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тесты для модели QuestionGroup - группы вопросов с общим stimulus
 */
class QuestionGroupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Тест: создание группы вопросов с базовыми данными
     */
    public function test_can_create_question_group(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        // Act
        $group = QuestionGroup::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'group_id' => 'task-I',
            'title' => 'Zadanie I',
            'instructions' => [
                'brief' => 'Listen to the audio',
                'full' => 'Listen carefully and answer all questions.',
            ],
            'stimulus' => [
                'audio' => ['https://example.com/audio.mp3'],
            ],
            'playback_settings' => [
                'max_plays' => 2,
                'enforcement' => 'strict',
            ],
            'order' => 1,
        ]);

        // Assert
        $this->assertInstanceOf(QuestionGroup::class, $group);
        $this->assertEquals($exam->id, $group->exam_id);
        $this->assertEquals($category->id, $group->section_id);
        $this->assertEquals('task-I', $group->group_id);
        $this->assertEquals('Zadanie I', $group->title);
        $this->assertIsArray($group->instructions);
        $this->assertIsArray($group->stimulus);
        $this->assertIsArray($group->playback_settings);

        // Проверить запись в БД
        $this->assertDatabaseHas('question_groups', [
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'group_id' => 'task-I',
        ]);
    }

    /**
     * Тест: связь с экзаменом (belongsTo Exam)
     */
    public function test_belongs_to_exam(): void
    {
        // Arrange & Act
        $group = QuestionGroup::factory()->create();

        // Assert
        $this->assertInstanceOf(Exam::class, $group->exam);
        $this->assertEquals($group->exam_id, $group->exam->id);
    }

    /**
     * Тест: связь с секцией (belongsTo ExamCategory)
     */
    public function test_belongs_to_section(): void
    {
        // Arrange & Act
        $group = QuestionGroup::factory()->create();

        // Assert
        $this->assertInstanceOf(ExamCategory::class, $group->section);
        $this->assertEquals($group->section_id, $group->section->id);
    }

    /**
     * Тест: связь с вопросами (hasMany Question)
     */
    public function test_has_many_questions(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);
        $group = QuestionGroup::factory()
            ->forExamAndSection($exam->id, $category->id)
            ->create();

        $question1 = Question::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_group_id' => $group->id,
        ]);

        $question2 = Question::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_group_id' => $group->id,
        ]);

        // Act
        $questions = $group->questions;

        // Assert
        $this->assertCount(2, $questions);
        $this->assertTrue($questions->contains($question1));
        $this->assertTrue($questions->contains($question2));
    }

    /**
     * Тест: accessor getAudioUrlsAttribute() - получение audio URLs из stimulus
     */
    public function test_get_audio_urls_attribute(): void
    {
        // Arrange
        $group = QuestionGroup::factory()->withAudio(2)->create();

        // Act
        $audioUrls = $group->audio_urls;

        // Assert
        $this->assertIsArray($audioUrls);
        $this->assertCount(2, $audioUrls);
        $this->assertStringContainsString('.mp3', $audioUrls[0]);
    }

    /**
     * Тест: accessor getMaxPlaysAttribute() - получение max_plays
     */
    public function test_get_max_plays_attribute(): void
    {
        // Arrange
        $groupStrict = QuestionGroup::factory()->withStrictPlayback(3)->create();
        $groupUnlimited = QuestionGroup::factory()->unlimited()->create();

        // Act & Assert
        $this->assertEquals(3, $groupStrict->max_plays);
        $this->assertNull($groupUnlimited->max_plays);
    }

    /**
     * Тест: accessor getPlaybackEnforcementAttribute() - получение enforcement режима
     */
    public function test_get_playback_enforcement_attribute(): void
    {
        // Arrange
        $groupStrict = QuestionGroup::factory()->withStrictPlayback()->create();
        $groupAdvisory = QuestionGroup::factory()->withAdvisoryPlayback()->create();
        $groupUnlimited = QuestionGroup::factory()->unlimited()->create();

        // Act & Assert
        $this->assertEquals('strict', $groupStrict->playback_enforcement);
        $this->assertEquals('advisory', $groupAdvisory->playback_enforcement);
        $this->assertEquals('none', $groupUnlimited->playback_enforcement);
    }

    /**
     * Тест: метод hasPlaybackLimit() - проверка наличия ограничения
     */
    public function test_has_playback_limit(): void
    {
        // Arrange
        $groupLimited = QuestionGroup::factory()->withStrictPlayback(2)->create();
        $groupUnlimited = QuestionGroup::factory()->unlimited()->create();

        // Act & Assert
        $this->assertTrue($groupLimited->hasPlaybackLimit());
        $this->assertFalse($groupUnlimited->hasPlaybackLimit());
    }

    /**
     * Тест: метод isStrictEnforcement() - проверка strict режима
     */
    public function test_is_strict_enforcement(): void
    {
        // Arrange
        $groupStrict = QuestionGroup::factory()->withStrictPlayback()->create();
        $groupAdvisory = QuestionGroup::factory()->withAdvisoryPlayback()->create();

        // Act & Assert
        $this->assertTrue($groupStrict->isStrictEnforcement());
        $this->assertFalse($groupAdvisory->isStrictEnforcement());
    }

    /**
     * Тест: accessor getStimulusTextAttribute() - получение текста stimulus
     */
    public function test_get_stimulus_text_attribute(): void
    {
        // Arrange
        $groupWithText = QuestionGroup::factory()->withText('<p>Test paragraph</p>')->create();
        $groupWithoutText = QuestionGroup::factory()->create();

        // Act & Assert
        $this->assertEquals('<p>Test paragraph</p>', $groupWithText->stimulus_text);
        $this->assertNull($groupWithoutText->stimulus_text);
    }

    /**
     * Тест: accessor getStimulusImagesAttribute() - получение изображений
     */
    public function test_get_stimulus_images_attribute(): void
    {
        // Arrange
        $group = QuestionGroup::factory()->withImages(2)->create();

        // Act
        $images = $group->stimulus_images;

        // Assert
        $this->assertIsArray($images);
        $this->assertCount(2, $images);
        $this->assertArrayHasKey('url', $images[0]);
        $this->assertArrayHasKey('alt', $images[0]);
    }

    /**
     * Тест: метод validate() - валидация минимума вопросов (должно быть >= 1)
     */
    public function test_validate_minimum_questions(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);
        $group = QuestionGroup::factory()
            ->forExamAndSection($exam->id, $category->id)
            ->create();

        // 0 вопросов - should fail
        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have at least 2 questions');

        $group->validate();
    }

    /**
     * Тест: метод validate() - fails с 1 вопросом (single-question groups not allowed)
     */
    public function test_validate_fails_with_single_question(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);
        $group = QuestionGroup::factory()
            ->forExamAndSection($exam->id, $category->id)
            ->create();

        // Создать 1 вопрос (should use placeholders instead)
        Question::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_group_id' => $group->id,
            'type' => 'speaking_prompt',
        ]);

        // Act & Assert - должен быть exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have at least 2 questions');
        $group->validate();
    }

    /**
     * Тест: метод validate() - проходит при >= 2 вопросах
     */
    public function test_validate_passes_with_two_questions(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);
        $group = QuestionGroup::factory()
            ->forExamAndSection($exam->id, $category->id)
            ->create();

        // Создать 2 вопроса
        Question::factory()->count(2)->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_group_id' => $group->id,
        ]);

        // Act & Assert - не должно быть exception
        $group->validate();
        $this->assertTrue(true); // Explicit assertion
    }

    /**
     * Тест: метод validate() - валидация max_plays (должно быть положительное число или null)
     */
    public function test_validate_max_plays_invalid(): void
    {
        // Arrange
        $group = QuestionGroup::factory()->create([
            'playback_settings' => [
                'max_plays' => -1, // Invalid negative
                'enforcement' => 'strict',
            ],
        ]);

        // Создать 2 вопроса для прохождения проверки количества
        Question::factory()->count(2)->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
        ]);

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a positive integer or null');

        $group->validate();
    }

    /**
     * Тест: метод validate() - валидация enforcement (допустимые значения)
     */
    public function test_validate_enforcement_invalid(): void
    {
        // Arrange
        $group = QuestionGroup::factory()->create([
            'playback_settings' => [
                'max_plays' => 2,
                'enforcement' => 'invalid_mode', // Invalid
            ],
        ]);

        // Создать 2 вопроса
        Question::factory()->count(2)->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
        ]);

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid enforcement mode');

        $group->validate();
    }

    /**
     * Тест: scope forSection() - фильтр по секции
     */
    public function test_scope_for_section(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category1 = ExamCategory::factory()->create(['exam_id' => $exam->id]);
        $category2 = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $group1 = QuestionGroup::factory()->forExamAndSection($exam->id, $category1->id)->create();
        $group2 = QuestionGroup::factory()->forExamAndSection($exam->id, $category2->id)->create();

        // Act
        $groups = QuestionGroup::forSection($category1->id)->get();

        // Assert
        $this->assertCount(1, $groups);
        $this->assertTrue($groups->contains($group1));
        $this->assertFalse($groups->contains($group2));
    }

    /**
     * Тест: scope forExam() - фильтр по экзамену
     */
    public function test_scope_for_exam(): void
    {
        // Arrange
        $exam1 = Exam::factory()->create();
        $exam2 = Exam::factory()->create();

        $group1 = QuestionGroup::factory()->create(['exam_id' => $exam1->id]);
        $group2 = QuestionGroup::factory()->create(['exam_id' => $exam2->id]);

        // Act
        $groups = QuestionGroup::forExam($exam1->id)->get();

        // Assert
        $this->assertCount(1, $groups);
        $this->assertTrue($groups->contains($group1));
        $this->assertFalse($groups->contains($group2));
    }

    /**
     * Тест: scope ordered() - сортировка по order
     */
    public function test_scope_ordered(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        $group3 = QuestionGroup::factory()->forExamAndSection($exam->id, $category->id)->withOrder(3)->create();
        $group1 = QuestionGroup::factory()->forExamAndSection($exam->id, $category->id)->withOrder(1)->create();
        $group2 = QuestionGroup::factory()->forExamAndSection($exam->id, $category->id)->withOrder(2)->create();

        // Act
        $groups = QuestionGroup::ordered()->get();

        // Assert
        $this->assertEquals($group1->id, $groups->first()->id);
        $this->assertEquals($group3->id, $groups->last()->id);
    }

    /**
     * Тест: метод hasAudio() - проверка наличия audio stimulus
     */
    public function test_has_audio(): void
    {
        // Arrange
        $groupWithAudio = QuestionGroup::factory()->withAudio()->create();
        $groupWithoutAudio = QuestionGroup::factory()->create();

        // Act & Assert
        $this->assertTrue($groupWithAudio->hasAudio());
        $this->assertFalse($groupWithoutAudio->hasAudio());
    }

    /**
     * Тест: метод hasText() - проверка наличия текста stimulus
     */
    public function test_has_text(): void
    {
        // Arrange
        $groupWithText = QuestionGroup::factory()->withText()->create();
        $groupWithoutText = QuestionGroup::factory()->create();

        // Act & Assert
        $this->assertTrue($groupWithText->hasText());
        $this->assertFalse($groupWithoutText->hasText());
    }

    /**
     * Тест: метод hasImages() - проверка наличия изображений
     */
    public function test_has_images(): void
    {
        // Arrange
        $groupWithImages = QuestionGroup::factory()->withImages()->create();
        $groupWithoutImages = QuestionGroup::factory()->create();

        // Act & Assert
        $this->assertTrue($groupWithImages->hasImages());
        $this->assertFalse($groupWithoutImages->hasImages());
    }

    /**
     * Тест: метод getPlaybackSettingsForUI() - форматирование для UI
     */
    public function test_get_playback_settings_for_ui(): void
    {
        // Arrange
        $group = QuestionGroup::factory()->withStrictPlayback(3)->create([
            'playback_settings' => [
                'max_plays' => 3,
                'enforcement' => 'strict',
                'show_counter' => true,
                'reset_on_navigation' => false,
            ],
        ]);

        // Act
        $uiSettings = $group->getPlaybackSettingsForUI();

        // Assert
        $this->assertIsArray($uiSettings);
        $this->assertEquals(3, $uiSettings['max_plays']);
        $this->assertEquals('strict', $uiSettings['enforcement']);
        $this->assertTrue($uiSettings['show_counter']);
        $this->assertFalse($uiSettings['reset_on_navigation']);
    }

    /**
     * Тест: фабрика polishTaskI() - польский экзамен Task I
     */
    public function test_factory_polish_task_i(): void
    {
        // Act
        $group = QuestionGroup::factory()->polishTaskI()->create();

        // Assert
        $this->assertEquals('task-I', $group->group_id);
        $this->assertEquals('Zadanie I', $group->title);
        $this->assertEquals(1, $group->max_plays);
        $this->assertEquals('strict', $group->playback_enforcement);
        $this->assertCount(1, $group->audio_urls);
    }

    /**
     * Тест: фабрика polishTaskII() - польский экзамен Task II
     */
    public function test_factory_polish_task_ii(): void
    {
        // Act
        $group = QuestionGroup::factory()->polishTaskII()->create();

        // Assert
        $this->assertEquals('task-II', $group->group_id);
        $this->assertEquals('Zadanie II', $group->title);
        $this->assertEquals(2, $group->max_plays);
        $this->assertEquals('strict', $group->playback_enforcement);
        $this->assertCount(1, $group->audio_urls);
    }

    /**
     * Тест: каскадное удаление - при удалении group, question_group_id устанавливается в null
     */
    public function test_cascade_delete_sets_null_on_questions(): void
    {
        // Arrange
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);
        $group = QuestionGroup::factory()->forExamAndSection($exam->id, $category->id)->create();

        $question = Question::factory()->create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_group_id' => $group->id,
        ]);

        // Act
        $group->delete();

        // Assert
        $question->refresh();
        $this->assertNull($question->question_group_id);
    }
}
