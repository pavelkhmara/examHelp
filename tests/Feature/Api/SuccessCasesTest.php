<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Success Cases Test
 *
 * Тестирует 4 успешных сценария создания экзаменов:
 * 1. IELTS Life Skills B1 с PDF файлом
 * 2. CCE B1 (Czech) с PDF файлом
 * 3. Goethe B1 (German) с PDF файлом
 * 4. Польский язык B1 без файла
 *
 * Все тесты используют without_confirmation=true для автоматического продолжения.
 *
 * @group broken
 */
class SuccessCasesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected array $testResults = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        // Use mock provider (default in testing environment)
        // This ensures consistent, fast, and reliable test results
        Log::info('=== Success Cases Test Started ===', [
            'ai_provider' => config('ai.provider'),
        ]);
    }

    protected function tearDown(): void
    {
        // Log all test results for documentation
        $resultsJson = json_encode($this->testResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        Log::info('=== Test Results ===', ['results' => $resultsJson]);

        parent::tearDown();
    }

    /**
     * Test Case 1: IELTS Life Skills B1 with PDF
     */
    public function test_ielts_life_skills_b1_success_case(): void
    {
        $startTime = microtime(true);
        $testName = 'IELTS Life Skills B1';

        Log::info("Starting test: {$testName}");

        // Create exam
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'IELTS Life Skills Level B1 Success Case',
                'level' => 'B1',
                'slug' => 'ielts-life-skills-b1-success-case',
                'description' => 'IELTS Life Skills test for UK visa applications',
            ]);

        $response->assertCreated();
        $examId = $response->json('data.id');

        Log::info('Exam created', ['exam_id' => $examId]);

        // Upload PDF document
        $pdfPath = base_path('files/pdf/ielts-life-skills-sample-paper-a-level-b1.pdf');
        $this->assertFileExists($pdfPath, "PDF file not found: {$pdfPath}");

        $uploadedFile = new UploadedFile(
            $pdfPath,
            'ielts-life-skills-sample-paper-a-level-b1.pdf',
            'application/pdf',
            null,
            true
        );

        // Start research with document and without_confirmation
        $researchResponse = $this->postJson("/api/exams/{$examId}/research", [
            'document' => $uploadedFile,
            'user_input' => json_encode([
                'language' => 'English',
                'exam_name' => 'IELTS Life Skills',
                'target' => ['level' => 'B1'],
                'where' => ['country' => 'UK', 'modality' => 'test_center'],
            ]),
            'without_confirmation' => true,
        ]);

        $researchResponse->assertAccepted();
        $taskId = $researchResponse->json('task_id');

        Log::info('Research started', ['task_id' => $taskId]);

        // Wait for task completion (max 2 minutes for testing)
        $completed = $this->waitForTaskCompletion($taskId, 120);

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        // Get final task status
        $task = \App\Models\GenerationTask::find($taskId);
        $exam = Exam::find($examId);

        $result = [
            'test_name' => $testName,
            'exam_id' => $examId,
            'task_id' => $taskId,
            'status' => $task->status,
            'research_status' => $exam->research_status,
            'duration_seconds' => $duration,
            'success' => $completed && $task->status === 'completed',
            'error' => $task->error,
            'categories_count' => $exam->categories()->count(),
            'identity' => $task->result['identity'] ?? null,
        ];

        $this->testResults[] = $result;

        Log::info('Test completed', $result);

        // Assertions
        $this->assertTrue($completed, "Task did not complete within timeout. Final status: {$task->status}, Error: {$task->error}, Research status: {$exam->research_status}");
        $this->assertEquals('completed', $task->status, "Task status should be completed, got: {$task->status}. Error: {$task->error}");
        $this->assertEquals('completed', $exam->research_status);
        $this->assertGreaterThan(0, $exam->categories()->count());
    }

    /**
     * Test Case 2: CCE B1 (Czech) with PDF
     */
    public function test_cce_czech_b1_success_case(): void
    {
        $startTime = microtime(true);
        $testName = 'CCE B1 Czech';

        Log::info("Starting test: {$testName}");

        // Create exam
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'CCE B1 Czech Language Success Case',
                'level' => 'B1',
                'slug' => 'cce-czech-b1-success-case',
                'description' => 'Certificate of Czech Language B1 level',
            ]);

        $response->assertCreated();
        $examId = $response->json('data.id');

        Log::info('Exam created', ['exam_id' => $examId]);

        // Upload PDF document
        $pdfPath = base_path('files/pdf/CCE-B1_modelova_varianta.pdf');
        $this->assertFileExists($pdfPath, "PDF file not found: {$pdfPath}");

        $uploadedFile = new UploadedFile(
            $pdfPath,
            'CCE-B1_modelova_varianta.pdf',
            'application/pdf',
            null,
            true
        );

        // Start research
        $researchResponse = $this->postJson("/api/exams/{$examId}/research", [
            'document' => $uploadedFile,
            'user_input' => json_encode([
                'language' => 'Czech',
                'exam_name' => 'CCE Czech Language Certificate',
                'target' => ['level' => 'B1'],
                'where' => ['country' => 'CZ', 'modality' => 'test_center'],
            ]),
            'without_confirmation' => true,
        ]);

        $researchResponse->assertAccepted();
        $taskId = $researchResponse->json('task_id');

        Log::info('Research started', ['task_id' => $taskId]);

        // Wait for completion
        $completed = $this->waitForTaskCompletion($taskId, 120);

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        // Get results
        $task = \App\Models\GenerationTask::find($taskId);
        $exam = Exam::find($examId);

        $result = [
            'test_name' => $testName,
            'exam_id' => $examId,
            'task_id' => $taskId,
            'status' => $task->status,
            'research_status' => $exam->research_status,
            'duration_seconds' => $duration,
            'success' => $completed && $task->status === 'completed',
            'error' => $task->error,
            'categories_count' => $exam->categories()->count(),
            'identity' => $task->result['identity'] ?? null,
        ];

        $this->testResults[] = $result;

        Log::info('Test completed', $result);

        // Assertions
        $this->assertTrue($completed, "Task did not complete within timeout. Final status: {$task->status}, Error: {$task->error}");
        $this->assertEquals('completed', $task->status, "Task status should be completed. Error: {$task->error}");
        $this->assertEquals('completed', $exam->research_status);
        $this->assertGreaterThan(0, $exam->categories()->count());
    }

    /**
     * Test Case 3: Goethe B1 (German) with PDF
     */
    public function test_goethe_german_b1_success_case(): void
    {
        $startTime = microtime(true);
        $testName = 'Goethe B1 German';

        Log::info("Starting test: {$testName}");

        // Create exam
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'Goethe-Zertifikat B1 Success Case',
                'level' => 'B1',
                'slug' => 'goethe-b1-success-case',
                'description' => 'Goethe-Institut German language certificate B1',
            ]);

        $response->assertCreated();
        $examId = $response->json('data.id');

        Log::info('Exam created', ['exam_id' => $examId]);

        // Upload PDF document
        $pdfPath = base_path('files/pdf/b1_modellsatz_erwachsene.pdf');
        $this->assertFileExists($pdfPath, "PDF file not found: {$pdfPath}");

        $uploadedFile = new UploadedFile(
            $pdfPath,
            'b1_modellsatz_erwachsene.pdf',
            'application/pdf',
            null,
            true
        );

        // Start research
        $researchResponse = $this->postJson("/api/exams/{$examId}/research", [
            'document' => $uploadedFile,
            'user_input' => json_encode([
                'language' => 'German',
                'exam_name' => 'Goethe-Zertifikat B1',
                'target' => ['level' => 'B1'],
                'where' => ['country' => 'DE', 'modality' => 'test_center'],
            ]),
            'without_confirmation' => true,
        ]);

        $researchResponse->assertAccepted();
        $taskId = $researchResponse->json('task_id');

        Log::info('Research started', ['task_id' => $taskId]);

        // Wait for completion
        $completed = $this->waitForTaskCompletion($taskId, 120);

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        // Get results
        $task = \App\Models\GenerationTask::find($taskId);
        $exam = Exam::find($examId);

        $result = [
            'test_name' => $testName,
            'exam_id' => $examId,
            'task_id' => $taskId,
            'status' => $task->status,
            'research_status' => $exam->research_status,
            'duration_seconds' => $duration,
            'success' => $completed && $task->status === 'completed',
            'error' => $task->error,
            'categories_count' => $exam->categories()->count(),
            'identity' => $task->result['identity'] ?? null,
        ];

        $this->testResults[] = $result;

        Log::info('Test completed', $result);

        // Assertions
        $this->assertTrue($completed, "Task did not complete within timeout. Final status: {$task->status}, Error: {$task->error}");
        $this->assertEquals('completed', $task->status, "Task status should be completed. Error: {$task->error}");
        $this->assertEquals('completed', $exam->research_status);
        $this->assertGreaterThan(0, $exam->categories()->count());
    }

    /**
     * Test Case 4: Polish B1 without file
     */
    public function test_polish_b1_without_file_success_case(): void
    {
        $startTime = microtime(true);
        $testName = 'Polish B1 without file';

        Log::info("Starting test: {$testName}");

        // Create exam
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/exams', [
                'title' => 'Polish Language Certificate B1 Success Case',
                'level' => 'B1',
                'slug' => 'polish-b1-success-case',
                'description' => 'Polish as a foreign language certificate B1',
            ]);

        $response->assertCreated();
        $examId = $response->json('data.id');

        Log::info('Exam created', ['exam_id' => $examId]);

        // Start research without file
        $researchResponse = $this->postJson("/api/exams/{$examId}/research", [
            'user_input' => json_encode([
                'language' => 'Polish',
                'exam_name' => 'Certificate of Polish as a Foreign Language',
                'target' => ['level' => 'B1'],
                'where' => ['country' => 'PL', 'modality' => 'test_center'],
                'notes' => 'Official certificate from Państwowa Komisja Poświadczania Znajomości Języka Polskiego jako Obcego',
            ]),
            'without_confirmation' => true,
        ]);

        $researchResponse->assertAccepted();
        $taskId = $researchResponse->json('task_id');

        Log::info('Research started', ['task_id' => $taskId]);

        // Wait for completion
        $completed = $this->waitForTaskCompletion($taskId, 120);

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        // Get results
        $task = \App\Models\GenerationTask::find($taskId);
        $exam = Exam::find($examId);

        $result = [
            'test_name' => $testName,
            'exam_id' => $examId,
            'task_id' => $taskId,
            'status' => $task->status,
            'research_status' => $exam->research_status,
            'duration_seconds' => $duration,
            'success' => $completed && $task->status === 'completed',
            'error' => $task->error,
            'categories_count' => $exam->categories()->count(),
            'identity' => $task->result['identity'] ?? null,
        ];

        $this->testResults[] = $result;

        Log::info('Test completed', $result);

        // Assertions
        $this->assertTrue($completed, "Task did not complete within timeout. Final status: {$task->status}, Error: {$task->error}");
        $this->assertEquals('completed', $task->status, "Task status should be completed. Error: {$task->error}");
        $this->assertEquals('completed', $exam->research_status);
        $this->assertGreaterThan(0, $exam->categories()->count());
    }

    /**
     * Wait for task completion with polling
     *
     * @return bool True if completed, false if timeout
     */
    protected function waitForTaskCompletion(int $taskId, int $timeoutSeconds = 600): bool
    {
        $startTime = time();
        $lastStatus = null;

        while (true) {
            $task = \App\Models\GenerationTask::find($taskId);

            if ($task->status !== $lastStatus) {
                Log::info('Task status changed', [
                    'task_id' => $taskId,
                    'status' => $task->status,
                    'elapsed' => time() - $startTime,
                ]);
                $lastStatus = $task->status;
            }

            if (in_array($task->status, ['completed', 'failed'])) {
                return $task->status === 'completed';
            }

            if (time() - $startTime > $timeoutSeconds) {
                Log::error('Task timeout', [
                    'task_id' => $taskId,
                    'status' => $task->status,
                    'timeout_seconds' => $timeoutSeconds,
                ]);

                return false;
            }

            sleep(5); // Check every 5 seconds
        }
    }
}
