# Complete Repository Context
Generated: Tue, Oct 21, 2025  9:12:03 AM

## 👑 Nova Resources


#### `app/Nova/Actions/ImportAiStructure.php`

```php
// Summary
16:class ImportAiStructure extends Action
22:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova\Actions;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Illuminate\Bus\Queueable;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ImportAiStructure extends Action
{
    use Queueable;

    public $name = 'Import AI Structure';

    public function fields(NovaRequest $request)
    {
        return [
            Boolean::make('Take Latest Task', 'take_latest')
                ->help('Если включено — возьмём JSON из последней завершенной GenerationTask этого экзамена.')
                ->default(true),
            Textarea::make('Paste JSON (optional)', 'json')
                ->help('Если выключить флаг выше — вставь сюда сырой JSON ответа от AI. Оставь пустым, чтобы использовать latest task.'),
        ];
    }

    public function handle(ActionFields $fields, $models)
    {
        /** @var ExamResearchService $svc */
        $svc = app(ExamResearchService::class);
        $validator = app(QuestionTypeContract::class);

        foreach ($models as $exam) {
            /** @var Exam $exam */

```

#### `app/Nova/Actions/ResearchAction.php`

```php
// Summary
12:class ResearchAction extends Action
18:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova\Actions;

use App\Jobs\RunExamResearchJob;
use Illuminate\Bus\Queueable;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ResearchAction extends Action
{
    use Queueable;

    public $name = 'Run Research Pipeline';

    public function fields(NovaRequest $request)
    {
        return [Textarea::make('Notes')->help('Optional context/hints')];
    }

    public function handle(ActionFields $fields, $models)
    {
        foreach ($models as $exam) {
            RunExamResearchJob::dispatch($exam->id, (string) ($fields->get('Notes') ?? null));
        }

        return Action::message('Research queued.');
    }
}

```

#### `app/Nova/Dashboards/Main.php`

```php
// Summary
8:class Main extends Dashboard

// Head
<?php

namespace App\Nova\Dashboards;

use Laravel\Nova\Cards\Help;
use Laravel\Nova\Dashboards\Main as Dashboard;

class Main extends Dashboard
{
    /**
     * Get the cards for the dashboard.
     *
     * @return array
     */
    public function cards()
    {
        return [
            new Help,
        ];
    }
}

```

#### `app/Nova/Exam.php`

```php
// Summary
18:class Exam extends Resource
20:    public static $model = \App\Models\Exam::class;
38:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova;

use App\Nova\Actions\ImportAiStructure;
use App\Nova\Actions\ResearchAction;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class Exam extends Resource
{
    public static $model = \App\Models\Exam::class;

    public static $title = 'title';

    public static $search = ['id', 'slug', 'title'];

    public static function label()
    {
        return 'Exams';
    }

    public static function singularLabel()
    {
        return 'Exam';
    }

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [

```

#### `app/Nova/ExamCategory.php`

```php
// Summary
15:class ExamCategory extends Resource
17:    public static $model = \App\Models\ExamCategory::class;
25:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova;

use App\Nova\Filters\ExamCategoryFilter;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class ExamCategory extends Resource
{
    public static $model = \App\Models\ExamCategory::class;

    public static $title = 'name';

    public static $search = ['id', 'key', 'name'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            // ID::make()->sortable(),
            // BelongsTo::make('Exam', 'exam', Exam::class)
            //     ->searchable()
            //     ->sortable()
            //     ->readonly(function ($request) {
            //         return !$request->isResourceIndexRequest() && !$request->isResourceDetailRequest();
            //     }),
            // Text::make('Key')->rules('required')->sortable(),
            // Text::make('Name')->rules('required')->sortable(),
            // Text::make('Description')->nullable()->hideFromIndex(),
            // Code::make('Meta')
            // ->json()
            // ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))

```

#### `app/Nova/ExamExampleQuestion.php`

```php
// Summary
14:class ExamExampleQuestion extends Resource
16:    public static $model = \App\Models\ExamExampleQuestion::class;
24:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova;

use App\Nova\Filters\ExamFilter;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Domain\Taxonomy\QuestionType;

class ExamExampleQuestion extends Resource
{
    public static $model = \App\Models\ExamExampleQuestion::class;

    public static $title = 'question';

    public static $search = ['id', 'question'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Exam', 'exam', Exam::class)
                ->searchable()
                ->sortable()
                ->readonly(function ($request) {
                    return ! $request->isResourceIndexRequest() && ! $request->isResourceDetailRequest();
                }),
            BelongsTo::make('Category', 'category', ExamCategory::class)
                ->searchable()
                ->nullable(),
                Badge::make('Type','type')
                        ->map([
                            QuestionType::SINGLE_SELECT->value   => 'info',
                            QuestionType::MULTI_SELECT->value    => 'info',

```

#### `app/Nova/Filters/ExamCategoryFilter.php`

```php
// Summary
10:class ExamCategoryFilter extends Filter

// Head
<?php

namespace App\Nova\Filters;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Filters\Filter;

class ExamCategoryFilter extends Filter
{
    public $name = 'Filter by Exam';

    public function apply(Request $request, $query, $value)
    {
        Log::debug('ExamCategoryFilter apply', [
            'viaResource' => $request->viaResource(),
            // 'viaResourceId' => $request->viaResourceId(),
            'viaRelationship' => $request->viaRelationship(),
            'exam_id_param' => $request->get('exam_id'),
            'all_params' => $request->all(),
            'value' => $value,
        ]);

        return $query->where('exam_id', $value);
    }

    public function options(Request $request)
    {
        return Exam::orderBy('title')
            ->get()
            ->pluck('id', 'title')
            ->toArray();
    }

    public function default()
    {
        // Если передан exam_id в URL, используем его как значение по умолчанию
        if (request()->has('exam_id')) {
            return request()->get('exam_id');

```

#### `app/Nova/Filters/ExamFilter.php`

```php
// Summary
9:class ExamFilter extends Filter

// Head
<?php

namespace App\Nova\Filters;

use App\Models\Exam;
use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class ExamFilter extends Filter
{
    /**
     * The filter's component.
     */
    public $component = 'select-filter';

    /**
     * Apply the filter to the given query.
     */
    public function apply(Request $request, $query, $value)
    {
        return $query->where('exam_id', $value);
    }

    /**
     * Get the filter's available options.
     */
    public function options(Request $request)
    {
        return Exam::orderBy('title')
            ->get()
            ->pluck('id', 'title')
            ->toArray();
    }

    /**
     * Get the displayable name of the filter.
     */
    public function name()
    {
        return 'Filter by Exam';

```

#### `app/Nova/GenerationLog.php`

```php
// Summary
13:class GenerationLog extends Resource
15:    public static $model = \App\Models\GenerationLog::class;
23:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class GenerationLog extends Resource
{
    public static $model = \App\Models\GenerationLog::class;

    public static $title = 'id';

    public static $search = ['id', 'stage'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Exam', 'exam', Exam::class)
                ->searchable()
                ->sortable()
                ->readonly(function ($request) {
                    return ! $request->isResourceIndexRequest() && ! $request->isResourceDetailRequest();
                }),
            BelongsTo::make('Task', 'task', GenerationTask::class)->searchable(),
            Text::make('Stage')->sortable(),
            Code::make('Request')->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->hideFromIndex(),
            Code::make('Response')->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->hideFromIndex(),

```

#### `app/Nova/GenerationTask.php`

```php
// Summary
14:class GenerationTask extends Resource
16:    public static $model = \App\Models\GenerationTask::class;
24:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class GenerationTask extends Resource
{
    public static $model = \App\Models\GenerationTask::class;

    public static $title = 'id';

    public static $search = ['id', 'type', 'status'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Exam', 'exam', Exam::class)
                ->searchable()
                ->sortable()
                ->readonly(function ($request) {
                    return ! $request->isResourceIndexRequest() && ! $request->isResourceDetailRequest();
                }),
            Text::make('Type')->sortable(),
            Select::make('Status')->options([
                'queued' => 'queued', 'running' => 'running', 'completed' => 'completed', 'failed' => 'failed',
            ])->displayUsingLabels()->sortable(),
            Number::make('Attempts')->sortable(),
            Code::make('Request')->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))

```

#### `app/Nova/Menu/CustomMenu.php`

```php
// Summary
13:class CustomMenu extends Tool

// Head
<?php

namespace App\Nova\Menu;

use App\Models\Exam;
use App\Nova\Filters\ExamCategoryFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Tool;

class CustomMenu extends Tool
{
    /**
     * Build the menu that renders the navigation links for the tool.
     *
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

```

#### `app/Nova/Resource.php`

```php
// Summary
8:abstract class Resource extends NovaResource

// Head
<?php

namespace App\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource as NovaResource;

abstract class Resource extends NovaResource
{
    /**
     * Build an "index" query for the given resource.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        return $query;
    }

    /**
     * Build a Scout search query for the given resource.
     *
     * @param  \Laravel\Scout\Builder  $query
     * @return \Laravel\Scout\Builder
     */
    public static function scoutQuery(NovaRequest $request, $query)
    {
        return $query;
    }

    /**
     * Build a "detail" query for the given resource.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function detailQuery(NovaRequest $request, $query)
    {
        return parent::detailQuery($request, $query);

```

#### `app/Nova/User.php`

```php
// Summary
13:class User extends Resource
20:    public static $model = \App\Models\User::class;
43:    public function fields(NovaRequest $request)

// Head
<?php

namespace App\Nova;

use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Laravel\Nova\Fields\Gravatar;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class User extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\User>
     */
    public static $model = \App\Models\User::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id', 'name', 'email',
    ];

    /**
     * Get the fields displayed by the resource.
     *

```
