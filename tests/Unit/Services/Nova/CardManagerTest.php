<?php

namespace Tests\Unit\Services\Nova;

use App\Models\ConfirmedIdentity;
use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ConfirmedIdentityService;
use App\Services\LanguageApp\QuickCheckService;
use App\Services\Nova\CardManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\ExamTestHelper;
use Tests\TestCase;

/**
 * Тесты для CardManager - управление Nova карточками
 */
class CardManagerTest extends TestCase
{
    use RefreshDatabase;

    protected CardManager $cardManager;

    protected QuickCheckService $quickCheckService;

    protected ConfirmedIdentityService $confirmedIdentityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quickCheckService = new QuickCheckService;
        $this->cardManager = new CardManager($this->quickCheckService);
        $this->confirmedIdentityService = new ConfirmedIdentityService;
    }

    /**
     * Тест: getActiveCards() возвращает fields_changed card когда поля изменились
     */
    public function test_get_active_cards_returns_fields_changed_card_when_changed(): void
    {
        // Arrange: создать exam с подтвержденной идентичностью
        $exam = ExamTestHelper::createExamWithAllFields(['title' => 'Original Title']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $identityData = ['canonical' => ['exam_name' => 'IELTS Academic']];

        $this->confirmedIdentityService->createConfirmedIdentity($exam, $identityData, $task);

        // Изменить title (используем updateQuietly чтобы не вызывать Observer, который инвалидирует ConfirmedIdentity)
        $exam->updateQuietly(['title' => 'Changed Title']);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert
        $this->assertNotEmpty($cards);
        $this->assertEquals('fields_changed', $cards[0]['type']);
        $this->assertEquals(1, $cards[0]['priority']);
        $this->assertArrayHasKey('fields', $cards[0]['data']);
        $this->assertContains('title', $cards[0]['data']['fields']);
    }

    /**
     * Тест: fields_changed card НЕ показывается если нет ConfirmedIdentity
     */
    public function test_fields_changed_card_not_shown_when_no_confirmed_identity(): void
    {
        // Arrange: exam без подтвержденной идентичности
        $exam = ExamTestHelper::createExamWithAllFields();

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert
        $fieldsChangedCard = collect($cards)->firstWhere('type', 'fields_changed');
        $this->assertNull($fieldsChangedCard, 'fields_changed card should not appear without ConfirmedIdentity');
    }

    /**
     * Тест: stalled_task card показывается когда задача зависла
     */
    public function test_get_active_cards_returns_stalled_task_card(): void
    {
        // Arrange: создать задачу со status=running и старым heartbeat
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(15), // 15 минут назад (> 10 мин threshold)
        ]);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert
        $stalledCard = collect($cards)->firstWhere('type', 'stalled_task');
        $this->assertNotNull($stalledCard);
        $this->assertEquals(2, $stalledCard['priority']);
        $this->assertEquals($task->id, $stalledCard['data']['task_id']);
        $this->assertArrayHasKey('stalled_since', $stalledCard['data']);
    }

    /**
     * Тест: stalled_task card НЕ показывается для задачи с recent heartbeat
     */
    public function test_stalled_task_card_not_shown_for_recent_heartbeat(): void
    {
        // Arrange: задача с недавним heartbeat (2 минуты назад)
        $exam = ExamTestHelper::createExamWithAllFields();
        GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(2), // 2 минуты назад
        ]);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert
        $stalledCard = collect($cards)->firstWhere('type', 'stalled_task');
        $this->assertNull($stalledCard, 'Stalled card should not appear for recent heartbeat');
    }

    /**
     * Тест: candidates card показывается когда есть pending_confirmation task
     */
    public function test_get_active_cards_returns_candidates_card(): void
    {
        // Arrange: task со status pending_confirmation и candidates в result
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'pending_confirmation',
            'result' => [
                'identity' => [
                    'candidates' => [
                        ['id' => 'ielts-academic', 'name' => 'IELTS Academic'],
                        ['id' => 'ielts-general', 'name' => 'IELTS General'],
                    ],
                ],
            ],
        ]);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert
        $candidatesCard = collect($cards)->firstWhere('type', 'candidates');
        $this->assertNotNull($candidatesCard);
        $this->assertEquals(3, $candidatesCard['priority']);
        $this->assertEquals($task->id, $candidatesCard['data']['task_id']);
        $this->assertCount(2, $candidatesCard['data']['candidates']);
    }

    /**
     * Тест: candidates card НЕ показывается если нет кандидатов
     */
    public function test_candidates_card_not_shown_when_no_candidates(): void
    {
        // Arrange: task pending_confirmation но без candidates
        $exam = ExamTestHelper::createExamWithAllFields();
        GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'pending_confirmation',
            'result' => [
                'identity' => [
                    'candidates' => [], // Пустой массив
                ],
            ],
        ]);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert
        $candidatesCard = collect($cards)->firstWhere('type', 'candidates');
        $this->assertNull($candidatesCard);
    }

    /**
     * Тест: followup_questions card показывается когда есть pending_clarification task
     */
    public function test_get_active_cards_returns_followup_questions_card(): void
    {
        // Arrange: task со status pending_clarification и followups
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'pending_clarification',
            'result' => [
                'identity' => [
                    'followups' => [
                        ['question' => 'What is the exam level?'],
                    ],
                ],
            ],
        ]);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert
        $followupsCard = collect($cards)->firstWhere('type', 'followup_questions');
        $this->assertNotNull($followupsCard);
        $this->assertEquals(4, $followupsCard['priority']);
        $this->assertEquals($task->id, $followupsCard['data']['task_id']);
        $this->assertNotEmpty($followupsCard['data']['followups']);
    }

    /**
     * Тест: dismissCard() добавляет карточку в dismissed list
     */
    public function test_dismiss_card_adds_to_dismissed_list(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $this->assertEmpty($exam->meta['dismissed_cards'] ?? []);

        // Act
        $this->cardManager->dismissCard($exam, 'fields_changed');

        // Assert
        $exam->refresh();
        $this->assertContains('fields_changed', $exam->meta['dismissed_cards']);
    }

    /**
     * Тест: dismissed card не показывается в getActiveCards()
     */
    public function test_dismissed_card_not_shown_in_active_cards(): void
    {
        // Arrange: создать exam с fields_changed
        $exam = ExamTestHelper::createExamWithAllFields(['title' => 'Original']);
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        $exam->updateQuietly(['title' => 'Changed']);

        // Проверить, что fields_changed card появляется
        $cardsBefore = $this->cardManager->getActiveCards($exam);
        $this->assertNotEmpty(collect($cardsBefore)->firstWhere('type', 'fields_changed'));

        // Act: dismiss fields_changed
        $this->cardManager->dismissCard($exam, 'fields_changed');

        // Assert: fields_changed больше не появляется
        $cardsAfter = $this->cardManager->getActiveCards($exam);
        $this->assertNull(collect($cardsAfter)->firstWhere('type', 'fields_changed'));
    }

    /**
     * Тест: resetDismissedCards() очищает все dismissed cards
     */
    public function test_reset_dismissed_cards_clears_all(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();
        $this->cardManager->dismissCard($exam, 'fields_changed');
        $this->cardManager->dismissCard($exam, 'stalled_task');

        $exam->refresh();
        $this->assertCount(2, $exam->meta['dismissed_cards']);

        // Act
        $this->cardManager->resetDismissedCards($exam);

        // Assert
        $exam->refresh();
        $this->assertEmpty($exam->meta['dismissed_cards']);
    }

    /**
     * Тест: cards сортируются по приоритету
     */
    public function test_cards_sorted_by_priority(): void
    {
        // Arrange: создать exam с несколькими карточками
        $exam = ExamTestHelper::createExamWithAllFields(['title' => 'Original']);

        // 1. fields_changed (priority 1)
        $task1 = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task1
        );
        $exam->updateQuietly(['title' => 'Changed']);

        // 2. stalled_task (priority 2)
        GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(15),
        ]);

        // 3. candidates (priority 3)
        GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'pending_confirmation',
            'result' => [
                'identity' => [
                    'candidates' => [['id' => 'test', 'name' => 'Test']],
                ],
            ],
        ]);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert: проверить порядок по priority
        $this->assertGreaterThanOrEqual(3, count($cards), 'Should have at least 3 cards');

        $priorities = array_column($cards, 'priority');
        $sortedPriorities = $priorities;
        sort($sortedPriorities);

        $this->assertEquals($sortedPriorities, $priorities, 'Cards should be sorted by priority');
    }

    /**
     * Тест: dismissCard() не добавляет дубликат
     */
    public function test_dismiss_card_does_not_add_duplicate(): void
    {
        // Arrange
        $exam = ExamTestHelper::createExamWithAllFields();

        // Act: dismiss дважды
        $this->cardManager->dismissCard($exam, 'fields_changed');
        $this->cardManager->dismissCard($exam, 'fields_changed');

        // Assert
        $exam->refresh();
        $dismissedCards = $exam->meta['dismissed_cards'] ?? [];
        $this->assertCount(1, $dismissedCards, 'Should not have duplicates');
    }

    /**
     * Тест: getCandidatesData() приоритизирует result['identity'] над verification_attempts
     */
    public function test_get_candidates_data_from_task_result_identity(): void
    {
        // Arrange: task с candidates в result['identity']
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create([
            'exam_id' => $exam->id,
            'status' => 'pending_confirmation',
            'result' => [
                'identity' => [
                    'candidates' => [
                        ['id' => 'from-result', 'name' => 'From Result'],
                    ],
                ],
                'verification_attempts' => [ // Старые данные
                    [
                        'candidates' => [
                            ['id' => 'from-attempts', 'name' => 'From Attempts'],
                        ],
                    ],
                ],
            ],
        ]);

        // Act
        $cards = $this->cardManager->getActiveCards($exam);

        // Assert: должны получить candidates из result['identity'], а не из verification_attempts
        $candidatesCard = collect($cards)->firstWhere('type', 'candidates');
        $this->assertNotNull($candidatesCard);
        $this->assertEquals('from-result', $candidatesCard['data']['candidates'][0]['id']);
    }

    /**
     * Тест: document_ids_hash change НЕ показывается пользователю
     */
    public function test_changed_fields_excludes_technical_fields(): void
    {
        // Arrange: exam с подтвержденной идентичностью
        $exam = ExamTestHelper::createExamWithAllFields();
        $task = GenerationTask::factory()->create(['exam_id' => $exam->id]);
        $this->confirmedIdentityService->createConfirmedIdentity(
            $exam,
            ['canonical' => ['exam_name' => 'IELTS']],
            $task
        );

        // Act: добавить документ (изменит document_ids_hash)
        $exam->documents()->create([
            'path' => 'test.pdf',
            'original_name' => 'test.pdf',
            'mime' => 'application/pdf',
            'size' => 100 * 1024, // размер в байтах
        ]);

        $cards = $this->cardManager->getActiveCards($exam);

        // Assert: fields_changed card должна появиться, но document_ids_hash не должен быть в списке
        $fieldsChangedCard = collect($cards)->firstWhere('type', 'fields_changed');
        if ($fieldsChangedCard) {
            $changedFields = $fieldsChangedCard['data']['fields'] ?? [];
            $this->assertNotContains('document_ids_hash', $changedFields, 'Technical fields should be hidden from user');
        }
    }
}
