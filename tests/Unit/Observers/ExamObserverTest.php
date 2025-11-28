<?php

namespace Tests\Unit\Observers;

use App\Models\ConfirmedIdentity;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ConfirmedIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * Тесты для ExamObserver - отслеживание изменений в Exam
 *
 * @group broken
 */
class ExamObserverTest extends TestCase
{
    use RefreshDatabase;

    protected ConfirmedIdentityService $confirmedIdentityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmedIdentityService = new ConfirmedIdentityService;
    }

    /**
     * Тест: изменение title инвалидирует ConfirmedIdentity
     */
    public function test_invalidates_confirmed_identity_when_title_changes(): void
    {
        // Arrange: создать exam с подтвержденной идентичностью
        $exam = ExamTestHelper::createExamWithAllFields(['title' => 'Original Title']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        $confirmed = $this->confirmedIdentityService->createConfirmedIdentity($exam, $identityData, $task);
        $this->assertTrue($confirmed->is_valid);

        // Act: изменить title
        $exam->update(['title' => 'Changed Title']);

        // Assert: ConfirmedIdentity должен быть инвалидирован
        $confirmed->refresh();
        $this->assertFalse($confirmed->is_valid, 'ConfirmedIdentity should be invalidated when title changes');
    }

    /**
     * Тест: изменение user_input инвалидирует ConfirmedIdentity
     */
    public function test_invalidates_confirmed_identity_when_user_input_changes(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields(['user_input' => 'Original input']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $confirmed = $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Act: изменить user_input
        $exam->update(['user_input' => 'Changed input']);

        // Assert
        $confirmed->refresh();
        $this->assertFalse($confirmed->is_valid);
    }

    /**
     * Тест: изменение level инвалидирует ConfirmedIdentity
     */
    public function test_invalidates_confirmed_identity_when_level_changes(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields(['level' => 'B2']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $confirmed = $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Act: изменить level
        $exam->update(['level' => 'C1']);

        // Assert
        $confirmed->refresh();
        $this->assertFalse($confirmed->is_valid);
    }

    /**
     * Тест: изменение description инвалидирует ConfirmedIdentity
     */
    public function test_invalidates_confirmed_identity_when_description_changes(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields(['description' => 'Original description']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $confirmed = $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Act: изменить description
        $exam->update(['description' => 'Changed description']);

        // Assert
        $confirmed->refresh();
        $this->assertFalse($confirmed->is_valid);
    }

    /**
     * Тест: изменение НЕ влияющих полей НЕ инвалидирует ConfirmedIdentity
     */
    public function test_does_not_invalidate_when_non_critical_fields_change(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields(['slug' => 'original-slug']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $confirmed = $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Act: изменить slug (НЕ влияет на identity)
        $exam->update(['slug' => 'changed-slug']);

        // Assert: ConfirmedIdentity должен остаться valid
        $confirmed->refresh();
        $this->assertTrue($confirmed->is_valid, 'ConfirmedIdentity should remain valid when non-critical fields change');
    }

    /**
     * Тест: изменение влияющего поля сбрасывает dismissed 'fields_changed' card
     */
    public function test_resets_dismissed_cards_on_field_change(): void
    {
        // Arrange: создать exam с dismissed 'fields_changed' card
        $exam = ExamTestHelper::createExamWithAllFields([
            'title' => 'Original Title',
            'meta' => [
                'dismissed_cards' => ['fields_changed', 'other_card'],
            ],
        ]);

        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Проверить начальное состояние
        $this->assertContains('fields_changed', $exam->meta['dismissed_cards']);

        // Act: изменить title
        $exam->update(['title' => 'Changed Title']);

        // Assert: 'fields_changed' должен быть удален из dismissed_cards
        $exam->refresh();
        $this->assertNotContains('fields_changed', $exam->meta['dismissed_cards'] ?? [], 'fields_changed should be removed from dismissed_cards');
        $this->assertContains('other_card', $exam->meta['dismissed_cards'], 'Other dismissed cards should remain');
    }

    /**
     * Тест: observer не вызывается повторно при saveQuietly() в updated()
     */
    public function test_does_not_trigger_infinite_loop(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields([
            'title' => 'Original',
            'meta' => ['dismissed_cards' => ['fields_changed']],
        ]);

        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Act: изменить title
        // Observer должен вызвать saveQuietly() для сброса dismissed_cards
        // но это НЕ должно вызвать observer снова (бесконечный цикл)
        $exam->update(['title' => 'Changed']);

        // Assert: проверить, что изменения применились
        $exam->refresh();
        $this->assertEquals('Changed', $exam->title);

        // Если бы был бесконечный цикл, тест бы завис или упал с timeout
        $this->assertTrue(true, 'No infinite loop occurred');
    }

    /**
     * Тест: created() генерирует slug если пустой
     */
    public function test_created_generates_slug_when_empty(): void
    {
        // Arrange & Act: создать exam без slug
        $exam = Exam::create([
            'title' => 'Test Exam For Slug',
            'slug' => null, // Пустой slug
        ]);

        // Assert: slug должен быть сгенерирован
        $this->assertNotNull($exam->slug);
        $this->assertStringContainsString('test-exam', strtolower($exam->slug));
    }

    /**
     * Тест: created() обеспечивает уникальность slug
     */
    public function test_created_ensures_slug_uniqueness(): void
    {
        // Arrange: создать exam с определенным slug
        $exam1 = Exam::create([
            'title' => 'Duplicate Title',
            'slug' => null,
        ]);

        $firstSlug = $exam1->slug;

        // Act: создать второй exam с таким же title
        $exam2 = Exam::create([
            'title' => 'Duplicate Title',
            'slug' => null,
        ]);

        // Assert: slugs должны отличаться
        $this->assertNotEquals($firstSlug, $exam2->slug, 'Slugs should be unique');
        $this->assertStringContainsString('duplicate-title', strtolower($exam2->slug));
    }

    /**
     * Тест: инвалидация работает для всех valid ConfirmedIdentities
     */
    public function test_invalidates_all_valid_confirmed_identities(): void
    {
        // Arrange: создать exam с двумя valid ConfirmedIdentities (unlikely но теоретически возможно)
        $exam = ExamTestHelper::createExamWithAllFields(['title' => 'Original']);

        $confirmed1 = ConfirmedIdentity::create([
            'exam_id' => $exam->id,
            'identity_data' => ['test' => 1],
            'source_fields' => ['title' => 'Original'],
            'confirmed_at' => now(),
            'is_valid' => true,
        ]);

        $confirmed2 = ConfirmedIdentity::create([
            'exam_id' => $exam->id,
            'identity_data' => ['test' => 2],
            'source_fields' => ['title' => 'Original'],
            'confirmed_at' => now(),
            'is_valid' => true,
        ]);

        // Act: изменить title
        $exam->update(['title' => 'Changed']);

        // Assert: оба ConfirmedIdentities должны быть инвалидированы
        $confirmed1->refresh();
        $confirmed2->refresh();
        $this->assertFalse($confirmed1->is_valid);
        $this->assertFalse($confirmed2->is_valid);
    }

    /**
     * Тест: изменение нескольких влияющих полей одновременно
     */
    public function test_handles_multiple_field_changes(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields([
            'title' => 'Original Title',
            'level' => 'B2',
            'description' => 'Original description',
        ]);

        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $confirmed = $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Act: изменить несколько полей одновременно
        $exam->update([
            'title' => 'Changed Title',
            'level' => 'C1',
            'description' => 'Changed description',
        ]);

        // Assert: ConfirmedIdentity инвалидирован
        $confirmed->refresh();
        $this->assertFalse($confirmed->is_valid);
    }
}
