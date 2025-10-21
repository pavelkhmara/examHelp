# Complete Repository Context
Generated: Tue, Oct 21, 2025  1:31:28 PM

## 🗃️  MODELS — Current State


#### `app/Models/Attempt.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
11:class Attempt extends Model
15:    protected $fillable = ['exam_id', 'user_id', 'started_at', 'completed_at', 'score'];
// Relations (best-effort grep)
19:        return $this->belongsTo(Exam::class);
24:        return $this->hasMany(AttemptAnswer::class);

// Head
<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attempt extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['exam_id', 'user_id', 'started_at', 'completed_at', 'score'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class);
    }
}

```

#### `app/Models/AttemptAnswer.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
10:class AttemptAnswer extends Model
14:    protected $fillable = ['attempt_id', 'question_id', 'selected_option_id', 'text_answer', 'is_correct'];
// Relations (best-effort grep)
18:        return $this->belongsTo(Attempt::class);
23:        return $this->belongsTo(Question::class);
28:        return $this->belongsTo(QuestionOption::class, 'selected_option_id');

// Head
<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptAnswer extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['attempt_id', 'question_id', 'selected_option_id', 'text_answer', 'is_correct'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'selected_option_id');
    }
}

```

#### `app/Models/Evaluation.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
7:class Evaluation extends Model
9:    protected $fillable = [
13:    protected $casts = [
// Relations (best-effort grep)
19:        return $this->belongsTo(Exam::class, 'exam_id', 'id');
24:        return $this->belongsTo(ExamCategory::class, 'exam_category_id');

// Head
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    protected $fillable = [
        'user_id', 'exam_id', 'exam_category_id', 'answer', 'result',
    ];

    protected $casts = [
        'result' => 'array',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo(ExamCategory::class, 'exam_category_id');
    }
}

```

#### `app/Models/Exam.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
10:class Exam extends Model
18:    protected $fillable = [
25:    protected $casts = [
// Relations (best-effort grep)
33:        return $this->hasMany(ExamCategory::class, 'exam_id', 'id');
38:        return $this->hasMany(ExamExampleQuestion::class);
43:        return $this->hasMany(GenerationTask::class, 'exam_id', 'id');
48:        return $this->hasMany(GenerationLog::class);

// Head
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'slug', 'title', 'description', 'level', 'is_active',
        'sources', 'meta', 'research_status',
        'categories_count', 'examples_count',
    ];

    protected $casts = [
        'sources' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(ExamCategory::class, 'exam_id', 'id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }

    public function generationTasks(): HasMany
    {
        return $this->hasMany(GenerationTask::class, 'exam_id', 'id');
    }

    public function generationLogs(): HasMany
    {
        return $this->hasMany(GenerationLog::class);
    }

    public function loadAllCounts()
    {
        return $this->loadCount([
            'categories',
            'examples',
            'generationTasks',
            'generationLogs',
        ]);
    }

    // Упрощённая структура экзамена из meta
    public function getExamStructureAttribute()
    {
        return $this->meta['exam_structure'] ?? null;
    }

    // Суммарная длительность из структуры (если есть)
    public function getTotalExamDurationAttribute()
    {
        return data_get($this->exam_structure, 'total_exam_duration');
    }

    // Удобный список секций (категорий) из структуры
    public function getStructureSectionsAttribute()
    {
        $s = $this->exam_structure;
        if (is_array($s)) {
            // поддержка как объекта с sections, так и массива верхнего уровня
            if (isset($s['sections']) && is_array($s['sections'])) {
                return $s['sections'];

```

#### `app/Models/ExamCategory.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
7:class ExamCategory extends Model
9:    protected $fillable = ['exam_id', 'key', 'name', 'meta', 'description', 'order'];
11:    protected $casts = ['meta' => 'array'];
// Relations (best-effort grep)
15:        return $this->belongsTo(Exam::class, 'exam_id', 'id');
20:        return $this->hasMany(ExamExampleQuestion::class);

// Head
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamCategory extends Model
{
    protected $fillable = ['exam_id', 'key', 'name', 'meta', 'description', 'order'];

    protected $casts = ['meta' => 'array'];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function examples()
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }
}

```

#### `app/Models/ExamExampleQuestion.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
10:class ExamExampleQuestion extends Model
14:    protected $table = 'exam_example_questions';
16:    protected $fillable = [
22:    protected $casts = [
// Relations (best-effort grep)
29:        return $this->belongsTo(Exam::class, 'exam_id', 'id');
34:        return $this->belongsTo(ExamCategory::class, 'exam_category_id');

// Head
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Questions\Validation\QuestionPayloadValidator;
use App\Domain\Taxonomy\QuestionType;

class ExamExampleQuestion extends Model
{
    use HasFactory;

    protected $table = 'exam_example_questions';

    protected $fillable = [
        'exam_id', 'exam_category_id', 'question',
        'good_answer', 'average_answer', 'bad_answer', 'rubric_breakdown',
        'type', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'type' => QuestionType::class,
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo(ExamCategory::class, 'exam_category_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $val = is_string($model->type) ? $model->type : ($model->type?->value ?? null);
            $type = (string) $model->type;
            $payload = (array) ($model->payload ?? []);
            // строгая валидация payload под тип
            QuestionPayloadValidator::validate($type, $payload);
            if (!in_array($val, QuestionType::all(), true)) {
                throw new \InvalidArgumentException("Invalid question type '{$val}' for ExamExampleQuestion");
            }
        });
    }
}

```

#### `app/Models/GenerationLog.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
8:class GenerationLog extends Model
10:    protected $fillable = [
15:    protected $casts = [
// Relations (best-effort grep)
23:        return $this->belongsTo(Exam::class);
28:        return $this->belongsTo(GenerationTask::class, 'generation_task_id');

// Head
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationLog extends Model
{
    protected $fillable = [
        'generation_task_id', 'stage', 'request', 'response',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
    ];

    protected $casts = [
        'exam_id' => 'string',
        'request' => 'array',
        'response' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function task()
    {
        return $this->belongsTo(GenerationTask::class, 'generation_task_id');
    }

    protected static function booted()
    {
        static::creating(function ($log) {
            if (empty($log->exam_id) && ! empty($log->generation_task_id)) {
                $task = \App\Models\GenerationTask::find($log->generation_task_id);
                if ($task && $task->exam_id) {
                    $log->exam_id = $task->exam_id;
                }
            }
        });
    }
}

```

#### `app/Models/GenerationTask.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
9:class GenerationTask extends Model
11:    protected $fillable = ['exam_id', 'type', 'status', 'request', 'response', 'error', 'attempts', 'result'];
13:    protected $casts = [
// Relations (best-effort grep)
21:        return $this->belongsTo(Exam::class);
26:        return $this->morphTo();
31:        return $this->hasMany(GenerationLog::class);

// Head
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GenerationTask extends Model
{
    protected $fillable = ['exam_id', 'type', 'status', 'request', 'response', 'error', 'attempts', 'result'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'result' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function logs()
    {
        return $this->hasMany(GenerationLog::class);
    }
}

```

#### `app/Models/Question.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
12:class Question extends Model
17:    protected $fillable = ['exam_id', 'type', 'prompt', 'position'];
19:    protected $casts = [
// Relations (best-effort grep)
25:        return $this->belongsTo(Exam::class);
30:        return $this->hasMany(QuestionOption::class);

// Head
<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Domain\Taxonomy\QuestionType;

class Question extends Model
{
    use HasFactory, HasUuid;

    // Схема: id, exam_id, type, prompt, position, timestamps
    protected $fillable = ['exam_id', 'type', 'prompt', 'position'];

    protected $casts = [
        'type' => QuestionType::class,
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            // Валидация известного типа (если enum каст вернул строку)
            $val = is_string($model->type) ? $model->type : ($model->type?->value ?? null);
            // Если у вас в QuestionType есть метод all(), оставьте как было; если нет — cases()
            if (method_exists(QuestionType::class, 'all')) {
                /** @phpstan-ignore-next-line */
                if (!in_array($val, QuestionType::all(), true)) {
                    throw new \InvalidArgumentException("Invalid question type '{$val}' for Question");
                }
            } else {
                $allowed = array_map(fn($c) => $c->value, QuestionType::cases());
                if (!in_array($val, $allowed, true)) {
                    throw new \InvalidArgumentException("Invalid question type '{$val}' for Question");
                }
            }
        });
    }
}

```

#### `app/Models/QuestionOption.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
10:class QuestionOption extends Model
14:    protected $fillable = ['question_id', 'text', 'is_correct'];
// Relations (best-effort grep)
18:        return $this->belongsTo(Question::class);

// Head
<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['question_id', 'text', 'is_correct'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}

```

#### `app/Models/User.php`

```php
// Summary: class/table/fillable/casts/hidden/guarded + relations
11:class User extends Authenticatable
21:    protected $fillable = [
32:    protected $hidden = [
// Relations (best-effort grep)

// Head
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

```
