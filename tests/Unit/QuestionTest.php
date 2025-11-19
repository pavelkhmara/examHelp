<?php

namespace Tests\Unit;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Question;
use App\Models\QuestionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тесты для модели Question - интеграция с QuestionGroup
 */
class QuestionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Тест: isGrouped() возвращает false для вопроса без группы
     */
    public function test_is_grouped_returns_false_for_ungrouped_question(): void
    {
        // Arrange
        $question = Question::factory()->create([
            'question_group_id' => null,
        ]);

        // Act
        $result = $question->isGrouped();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Тест: isGrouped() возвращает true для вопроса с группой
     */
    public function test_is_grouped_returns_true_for_grouped_question(): void
    {
        // Arrange
        $group = QuestionGroup::factory()->create();
        $question = Question::factory()->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
        ]);

        // Act
        $result = $question->isGrouped();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Тест: getResolvedStimulus() возвращает только stimulus вопроса если нет группы
     */
    public function test_get_resolved_stimulus_returns_question_stimulus_when_not_grouped(): void
    {
        // Arrange
        $questionStimulus = [
            'text_html' => '<p>Question text</p>',
            'images' => [
                ['url' => 'https://example.com/image1.jpg'],
            ],
        ];

        $question = Question::factory()->create([
            'question_group_id' => null,
            'stimulus' => $questionStimulus,
        ]);

        // Act
        $resolved = $question->getResolvedStimulus();

        // Assert
        $this->assertEquals($questionStimulus, $resolved);
    }

    /**
     * Тест: getResolvedStimulus() возвращает group stimulus когда question stimulus пустой
     */
    public function test_get_resolved_stimulus_returns_group_stimulus_when_question_has_no_stimulus(): void
    {
        // Arrange
        $groupStimulus = [
            'audio' => ['https://example.com/audio.mp3'],
            'text_html' => '<p>Group text</p>',
        ];

        $group = QuestionGroup::factory()->create([
            'stimulus' => $groupStimulus,
        ]);

        $question = Question::factory()->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
            'stimulus' => [],
        ]);

        // Act
        $resolved = $question->getResolvedStimulus();

        // Assert
        $this->assertEquals($groupStimulus, $resolved);
    }

    /**
     * Тест: getResolvedStimulus() объединяет group и question stimulus (merge arrays)
     */
    public function test_get_resolved_stimulus_merges_group_and_question_stimulus(): void
    {
        // Arrange
        $groupStimulus = [
            'audio' => ['https://example.com/group-audio.mp3'],
            'text_html' => '<p>Group text</p>',
            'images' => [
                ['url' => 'https://example.com/group-img1.jpg'],
            ],
        ];

        $questionStimulus = [
            'images' => [
                ['url' => 'https://example.com/question-img1.jpg'],
            ],
            'video' => ['https://example.com/video.mp4'],
        ];

        $group = QuestionGroup::factory()->create([
            'stimulus' => $groupStimulus,
        ]);

        $question = Question::factory()->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
            'stimulus' => $questionStimulus,
        ]);

        // Act
        $resolved = $question->getResolvedStimulus();

        // Assert
        // Audio из группы
        $this->assertArrayHasKey('audio', $resolved);
        $this->assertEquals(['https://example.com/group-audio.mp3'], $resolved['audio']);

        // Images объединены (group + question)
        $this->assertArrayHasKey('images', $resolved);
        $this->assertCount(2, $resolved['images']);
        $this->assertEquals('https://example.com/group-img1.jpg', $resolved['images'][0]['url']);
        $this->assertEquals('https://example.com/question-img1.jpg', $resolved['images'][1]['url']);

        // Video только из вопроса
        $this->assertArrayHasKey('video', $resolved);
        $this->assertEquals(['https://example.com/video.mp4'], $resolved['video']);

        // Text_html из группы (скалярное значение - не объединяется)
        $this->assertArrayHasKey('text_html', $resolved);
        $this->assertEquals('<p>Group text</p>', $resolved['text_html']);
    }

    /**
     * Тест: getResolvedStimulus() - question stimulus переопределяет scalar значения группы
     */
    public function test_get_resolved_stimulus_question_overrides_group_scalar_values(): void
    {
        // Arrange
        $groupStimulus = [
            'text_html' => '<p>Group text</p>',
            'audio' => ['https://example.com/group-audio.mp3'],
        ];

        $questionStimulus = [
            'text_html' => '<p>Question text overrides group</p>',
        ];

        $group = QuestionGroup::factory()->create([
            'stimulus' => $groupStimulus,
        ]);

        $question = Question::factory()->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
            'stimulus' => $questionStimulus,
        ]);

        // Act
        $resolved = $question->getResolvedStimulus();

        // Assert
        // Text_html переопределен вопросом
        $this->assertEquals('<p>Question text overrides group</p>', $resolved['text_html']);

        // Audio остался из группы
        $this->assertEquals(['https://example.com/group-audio.mp3'], $resolved['audio']);
    }

    /**
     * Тест: questionGroup() relationship возвращает QuestionGroup
     */
    public function test_question_group_relationship_returns_question_group(): void
    {
        // Arrange
        $group = QuestionGroup::factory()->create();
        $question = Question::factory()->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
        ]);

        // Act
        $loadedGroup = $question->questionGroup;

        // Assert
        $this->assertInstanceOf(QuestionGroup::class, $loadedGroup);
        $this->assertEquals($group->id, $loadedGroup->id);
        $this->assertEquals($group->group_id, $loadedGroup->group_id);
    }

    /**
     * Тест: questionGroup() relationship возвращает null для вопроса без группы
     */
    public function test_question_group_relationship_returns_null_for_ungrouped_question(): void
    {
        // Arrange
        $question = Question::factory()->create([
            'question_group_id' => null,
        ]);

        // Act
        $loadedGroup = $question->questionGroup;

        // Assert
        $this->assertNull($loadedGroup);
    }

    /**
     * Тест: getResolvedStimulus() работает корректно когда group не загружена (lazy loading)
     */
    public function test_get_resolved_stimulus_works_with_lazy_loading(): void
    {
        // Arrange
        $groupStimulus = [
            'audio' => ['https://example.com/audio.mp3'],
        ];

        $group = QuestionGroup::factory()->create([
            'stimulus' => $groupStimulus,
        ]);

        $question = Question::factory()->create([
            'exam_id' => $group->exam_id,
            'section_id' => $group->section_id,
            'question_group_id' => $group->id,
            'stimulus' => ['images' => []],
        ]);

        // Убедимся, что relationship не загружена заранее
        $this->assertFalse($question->relationLoaded('questionGroup'));

        // Act
        $resolved = $question->getResolvedStimulus();

        // Assert
        $this->assertArrayHasKey('audio', $resolved);
        $this->assertEquals(['https://example.com/audio.mp3'], $resolved['audio']);

        // Relationship должна была загрузиться автоматически
        $this->assertTrue($question->relationLoaded('questionGroup'));
    }
}
