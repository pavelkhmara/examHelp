    /**
     * Manually resolve generation plans for an exam
     * POST /api/exams/{examId}/resolve-plans
     */
    public function resolvePlans(string $examId): JsonResponse
    {
        try {
            $exam = Exam::findOrFail($examId);

            // Check if exam has structure_v2
            if (empty($exam->meta['structure_v2']['sections'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No structure_v2 found. Run research first.',
                ], 400);
            }

            // Check if categories exist
            $categoriesCount = $exam->categories()->count();
            if ($categoriesCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No ExamCategories found. Structure must be materialized first.',
                ], 400);
            }

            // Resolve plans using DI
            $resolver = app(\App\Services\LanguageApp\AssemblyResolver::class);
            $plans = $resolver->resolve($exam);

            return response()->json([
                'success' => true,
                'message' => 'Generation plans resolved successfully',
                'data' => [
                    'plans_count' => count($plans),
                    'plans' => collect($plans)->map(fn ($p) => [
                        'id' => $p->id,
                        'section_id' => $p->section_id,
                        'assembly_mode' => $p->assembly_mode,
                        'total_questions' => $p->total_questions,
                        'question_groups_count' => count($p->plan_data['question_groups'] ?? []),
                        'placeholders_count' => count($p->plan_data['placeholders'] ?? []),
                    ])->toArray(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('resolvePlans error', [
                'examId' => $examId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve plans: ' . $e->getMessage(),
            ], 500);
        }
    }
