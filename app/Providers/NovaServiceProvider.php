<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;
use Laravel\Nova\Menu\Menu;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use App\Models\Exam;
use App\Nova\Exam as ExamResource;
use App\Nova\ExamCategory as ExamCategoryResource;
use App\Nova\ExamExampleQuestion as ExamExampleQuestionResource;
use App\Nova\GenerationLog as GenerationLogResource;
use App\Nova\GenerationTask as GenerationTaskResource;
use App\Nova\Menu\CustomMenu;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    public function menu(Request $request)
    {
        // Log::info('Nova menu rebuilt');

        // // 1) Глобальные (как сейчас) — оставляем
        // $global = [
        //     MenuSection::make('Language App', [
        //         MenuItem::resource(ExamResource::class),
        //         MenuItem::resource(ExamCategoryResource::class),
        //         MenuItem::resource(ExamExampleQuestionResource::class),
        //         MenuItem::resource(GenerationLogResource::class),
        //         MenuItem::resource(GenerationTaskResource::class),
        //     ])->icon('book-open')->collapsable(),
        // ];

        // // 2) Динамически сформированные разделы по каждому экзамену
        // $examSections = [];
        // Exam::query()
        //     ->orderBy('title')
        //     ->get(['id','slug','title'])
        //     ->each(function (Exam $exam) use (&$examSections) {
        //         $id    = $exam->id;                 // UUID
        //         $short = mb_strimwidth($exam->title ?? ($exam->slug ?? 'Exam'), 0, 40, '…', 'UTF-8');
        //         $label = substr($id, 0, 8).' · '.$short; // «${id8} · Короткое название»

        //         $examSections[] = MenuSection::make($label, [
        //             // Overview (карточка самого экзамена)
        //             MenuItem::link('Overview', "/nova/resources/".ExamResource::uriKey()."/{$id}"),

        //             // Дочерние списки, отфильтрованные по этому экзамену
        //             MenuItem::link('Categories', "/nova/resources/".ExamCategoryResource::uriKey()."?exam={$id}"),
        //             MenuItem::link('Example Questions', "/nova/resources/".ExamExampleQuestionResource::uriKey()."?exam={$id}"),
        //             MenuItem::link('Generation Logs', "/nova/resources/".GenerationLogResource::uriKey()."?exam={$id}"),
        //             MenuItem::link('Generation Tasks', "/nova/resources/".GenerationTaskResource::uriKey()."?exam={$id}"),
        //         ])->collapsable()->collapsedByDefault();
        //     });

        // // 3) Отдельная секция «Exams» как контейнер, чтобы все экзамены были выпадающим списком
        // $byExams = MenuSection::make('Exams', $examSections)
        //     ->icon('collection')
        //     ->collapsable()
        //     ->collapsedByDefault();

        // // Итоговое меню = глобальные пункты + «Exams» со всеми экземплярами
        // return new Menu(array_merge($global, [$byExams]));
    }
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        Nova::mainMenu(function (Request $request) {
            return (new CustomMenu)->menu($request);
        });
    }

    /**
     * Register the Nova routes.
     *
     * @return void
     */
    protected function routes()
    {
        Nova::routes()
                ->withAuthenticationRoutes()
                ->withPasswordResetRoutes()
                ->register();
    }

    /**
     * Register the Nova gate.
     *
     * This gate determines who can access Nova in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        Gate::define('viewNova', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array
     */
    protected function dashboards()
    {
        return [
            new \App\Nova\Dashboards\Main,
        ];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array
     */
    public function tools()
    {
        return [];
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
