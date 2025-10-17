<?php

namespace App\Nova\Menu;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool;
use Illuminate\Http\Request;
use App\Models\Exam;
use App\Nova\Filters\ExamCategoryFilter;

class CustomMenu extends Tool
{
    /**
     * Build the menu that renders the navigation links for the tool.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function menu(Request $request)
    {
        return [
            // Главное меню "Exams" с выпадающим списком всех экзаменов
            MenuSection::make('Exams', $this->createExamsSubmenu())
                ->icon('book')
                ->collapsable(),

            // Отдельные разделы для всех сущностей
            MenuSection::make('data', [
                MenuItem::resource(\App\Nova\ExamCategory::class)
                    ->name('All Categories'),
                MenuItem::resource(\App\Nova\ExamExampleQuestion::class)
                    ->name('All Example Questions'),
                MenuItem::resource(\App\Nova\GenerationTask::class)
                    ->name('All Generation Tasks'),
                MenuItem::resource(\App\Nova\GenerationLog::class)
                    ->name('All Generation Logs'),

            ])
            ->icon('document-text')
            ->collapsable(),
        ];
    }

    /**
     * Создает выпадающее меню со всеми экзаменами и их связанными сущностями
     */
    private function createExamsSubmenu()
    {
        $examItems = [];

        try {
            // Безопасно получаем экзамены без проблемных счетчиков
            $exams = Exam::withCount(['categories', 'examples'])
                ->orderBy('title')
                ->get();

            foreach ($exams as $exam) {
                $examLabel = "{$exam->title}";

                // $examId = $exam->id; // UUID нужного экзамена
                $examId = 'a02051f8-2ada-407d-8346-feb02588b8aa';
                $filtersPayload = [
                    ['class' => ExamCategoryFilter::class, 'value' => $examId],
                ];
                $filters = base64_encode(json_encode($filtersPayload, JSON_UNESCAPED_SLASHES));
                $query = 'filters=' . rawurlencode($filters);
                // $url = '/resources/exam-categories' . '?filters=' . rawurlencode($filters);
                $url = '/resources/exam-categories/' . '?' . $query;
                
                // Получаем счетчики безопасным способом
                $categoriesCount = $exam->categories_count ?? $exam->categories()->count();
                $examplesCount = $exam->examples_count ?? $exam->examples()->count();
                $tasksCount = $exam->generationTasks()->count();
                // $logsCount = $exam->generationLogs()->count();

                // Создаем подменю для каждого экзамена
                // $examItems[] = MenuSection::make($examLabel, [
                    // Карточка самого экзамена
                    $examItems[] = MenuItem::link($examLabel, "/resources/exams/{$exam->id}");
                    // MenuItem::link('Exam Details', "/resources/exams/{$exam->id}"),

                    // Категории этого экзамена
                    // MenuItem::link(
                    //     "Categories ({$categoriesCount})",
                    //     $url
                    //     // "/resources/exam-categories/lens/exam-categories-by-exam?exam_id={$exam->id}"
                    //     // "/resources/exam-categories?viaResource=exams&viaResourceId={$exam->id}&viaRelationship=categories"
                    //     // "/resources/exam-categories?exam={$exam->id}"
                    //     // "/resources/exam-categories?exam_id={$exam->id}"
                    // ),

                    // // Примеры вопросов этого экзамена
                    // MenuItem::link(
                    //     "Example Questions ({$examplesCount})",
                    //     "/resources/exam-example-questions?exam={$exam->id}"
                    // ),

                    // // Задачи генерации этого экзамена
                    // MenuItem::link(
                    //     "Generation Tasks ({$tasksCount})",
                    //     "/resources/generation-tasks?exam={$exam->id}"
                    // ),

                    // // Логи генерации этого экзамена
                    // MenuItem::link(
                    //     "Generation Logs",
                    //     "/resources/generation-logs?exam={$exam->id}"
                    // ),

                // ])->icon('document-text')->collapsable();
            }
        } catch (\Exception $e) {
            // Если возникла ошибка, просто показываем базовое меню
            Log::error('Error building exam menu: ' . $e->getMessage());
            
            $examItems[] = MenuItem::link('All Exams', '/resources/exams');
        }

        // Добавляем создание нового экзамена в начало (просто как MenuItem)
        array_unshift($examItems, 
            MenuItem::link('Create New Exam', '/resources/exams/new')
        );

        return $examItems;
    }
}