<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\QuestionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * QuestionGroup Factory
 *
 * Creates question groups with shared stimulus and playback controls
 *
 * @extends Factory<QuestionGroup>
 */
class QuestionGroupFactory extends Factory
{
    protected $model = QuestionGroup::class;

    public function definition(): array
    {
        // Create exam and category
        $exam = Exam::factory()->create();
        $category = ExamCategory::factory()->create(['exam_id' => $exam->id]);

        return [
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'group_id' => 'task-'.strtoupper($this->faker->lexify('??')),
            'title' => 'Task '.strtoupper($this->faker->lexify('?')).': '.$this->faker->sentence(3),
            'instructions' => [
                'brief' => 'Listen to the audio and answer the questions.',
                'full' => 'You will hear a recording. Listen carefully and answer all questions in this section.',
            ],
            'stimulus' => [
                'text_html' => null,
                'images' => [],
                'audio' => [],
                'video' => [],
            ],
            'playback_settings' => [
                'max_plays' => 2,
                'enforcement' => 'advisory',
                'show_counter' => true,
                'reset_on_navigation' => true,
            ],
            'metadata' => [
                'duration_sec' => 180,
                'estimated_time' => 300,
            ],
            'order' => 0,
        ];
    }

    /**
     * Group with audio stimulus (listening comprehension)
     */
    public function withAudio(int $count = 1): self
    {
        return $this->state(function (array $attributes) use ($count) {
            $audioUrls = [];
            for ($i = 0; $i < $count; $i++) {
                $audioUrls[] = 'https://example.com/audio/task-'.$this->faker->uuid.'.mp3';
            }

            $attributes['stimulus']['audio'] = $audioUrls;

            return $attributes;
        });
    }

    /**
     * Group with text stimulus (reading comprehension)
     */
    public function withText(?string $html = null): self
    {
        return $this->state(function (array $attributes) use ($html) {
            $attributes['stimulus']['text_html'] = $html ?? '<p>'.$this->faker->paragraphs(3, true).'</p>';

            return $attributes;
        });
    }

    /**
     * Group with images
     */
    public function withImages(int $count = 1): self
    {
        return $this->state(function (array $attributes) use ($count) {
            $images = [];
            for ($i = 0; $i < $count; $i++) {
                $images[] = [
                    'url' => 'https://example.com/images/img-'.$this->faker->uuid.'.jpg',
                    'alt' => $this->faker->sentence(3),
                    'caption' => $this->faker->sentence(5),
                ];
            }

            $attributes['stimulus']['images'] = $images;

            return $attributes;
        });
    }

    /**
     * Strict playback enforcement (cannot proceed if limit exceeded)
     */
    public function withStrictPlayback(int $maxPlays = 2): self
    {
        return $this->state(function (array $attributes) use ($maxPlays) {
            $attributes['playback_settings'] = [
                'max_plays' => $maxPlays,
                'enforcement' => 'strict',
                'show_counter' => true,
                'reset_on_navigation' => false,
            ];

            return $attributes;
        });
    }

    /**
     * Advisory playback (shows warning but allows proceeding)
     */
    public function withAdvisoryPlayback(int $maxPlays = 2): self
    {
        return $this->state(function (array $attributes) use ($maxPlays) {
            $attributes['playback_settings'] = [
                'max_plays' => $maxPlays,
                'enforcement' => 'advisory',
                'show_counter' => true,
                'reset_on_navigation' => true,
            ];

            return $attributes;
        });
    }

    /**
     * Unlimited playback (no restrictions)
     */
    public function unlimited(): self
    {
        return $this->state(function (array $attributes) {
            $attributes['playback_settings'] = [
                'max_plays' => null,
                'enforcement' => 'none',
                'show_counter' => false,
                'reset_on_navigation' => false,
            ];

            return $attributes;
        });
    }

    /**
     * Polish exam Task I pattern (6 questions, 1 audio, played once)
     */
    public function polishTaskI(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'group_id' => 'task-I',
                'title' => 'Zadanie I',
                'instructions' => [
                    'brief' => 'Usłyszysz dwukrotnie trzy teksty. Na podstawie informacji zawartych w nagraniu wykonaj zadania 1–6.',
                    'full' => 'Zapoznaj się z treścią zadań, a następnie posłuchaj nagrania i wykonaj polecenia. Po pierwszym i drugim wysłuchaniu nagrania będziesz mieć czas na uzupełnienie odpowiedzi.',
                ],
                'stimulus' => [
                    'audio' => ['https://example.com/audio/pl-c1-task-1.mp3'],
                    'text_html' => null,
                    'images' => [],
                    'video' => [],
                ],
                'playback_settings' => [
                    'max_plays' => 1,
                    'enforcement' => 'strict',
                    'show_counter' => true,
                    'reset_on_navigation' => false,
                ],
                'metadata' => [
                    'duration_sec' => 240,
                    'estimated_time' => 360,
                    'question_count' => 6,
                ],
                'order' => 1,
            ];
        });
    }

    /**
     * Polish exam Task II pattern (8 questions, 1 audio, played twice)
     */
    public function polishTaskII(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'group_id' => 'task-II',
                'title' => 'Zadanie II',
                'instructions' => [
                    'brief' => 'Usłyszysz dwukrotnie wywiad. Na podstawie informacji zawartych w nagraniu wykonaj zadania 7–14.',
                    'full' => 'Zapoznaj się z treścią zadań, a następnie posłuchaj nagrania i wykonaj polecenia. Po pierwszym i drugim wysłuchaniu nagrania będziesz mieć czas na uzupełnienie odpowiedzi.',
                ],
                'stimulus' => [
                    'audio' => ['https://example.com/audio/pl-c1-task-2.mp3'],
                    'text_html' => null,
                    'images' => [],
                    'video' => [],
                ],
                'playback_settings' => [
                    'max_plays' => 2,
                    'enforcement' => 'strict',
                    'show_counter' => true,
                    'reset_on_navigation' => false,
                ],
                'metadata' => [
                    'duration_sec' => 300,
                    'estimated_time' => 480,
                    'question_count' => 8,
                ],
                'order' => 2,
            ];
        });
    }

    /**
     * Create group for specific exam and section
     */
    public function forExamAndSection(string $examId, int $sectionId): self
    {
        return $this->state(function (array $attributes) use ($examId, $sectionId) {
            return [
                'exam_id' => $examId,
                'section_id' => $sectionId,
            ];
        });
    }

    /**
     * Set display order
     */
    public function withOrder(int $order): self
    {
        return $this->state(fn (array $attributes) => ['order' => $order]);
    }
}
