<?php

namespace App\Services\Nova;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\Question;
use App\Models\QuestionGroup;

/**
 * ExamFullViewRenderer Service
 *
 * Renders full exam view with all questions in HTML format.
 * Handles different assembly modes (inline, blueprint, pool) and
 * prioritizes generated questions over example questions.
 */
class ExamFullViewRenderer
{
    /**
     * Render full exam view
     */
    public function renderExam(Exam $exam): string
    {
        // Add CSS for collapsible sections
        $html = '<style>
            details.exam-section summary .section-toggle-icon {
                display: inline-block;
                transition: transform 0.2s ease;
            }
            details.exam-section[open] summary .section-toggle-icon {
                transform: rotate(90deg);
            }
            details.exam-section summary::-webkit-details-marker {
                display: none;
            }
        </style>';

        $html .= '<div class="exam-full-view" style="font-family: system-ui, -apple-system, sans-serif; color: #111827; line-height: 1.6;">';

        // Exam header
        $html .= $this->renderExamHeader($exam);

        // Sections
        $sections = $exam->structure_sections ?? [];
        if (empty($sections)) {
            $html .= $this->renderEmptyState('Нет структуры экзамена. Запустите Research для создания структуры.');
        } else {
            foreach ($sections as $sectionData) {
                $html .= $this->renderSection($exam, $sectionData);
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render exam header with metadata
     */
    private function renderExamHeader(Exam $exam): string
    {
        $html = '<div class="exam-header" style="background: #ffffff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.06); border: 1px solid #e5e7eb;">';

        // Title
        $html .= '<h1 style="margin: 0 0 16px; font-size: 28px; font-weight: 700; color: #111827;">';
        $html .= htmlspecialchars($exam->title ?? 'Untitled Exam');
        $html .= '</h1>';

        // Metadata badges
        $html .= '<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px;">';

        if ($exam->level) {
            $html .= $this->renderBadge($exam->level, 'level');
        }

        if ($exam->language_of_test) {
            $html .= $this->renderBadge($exam->language_of_test, 'language');
        }

        $structure = $exam->structure_v2 ?? [];
        if (isset($structure['meta']['provider'])) {
            $html .= $this->renderBadge($structure['meta']['provider'], 'provider');
        }

        $html .= '</div>';

        // Description
        if ($exam->description) {
            $html .= '<p style="margin: 0; color: #4b5563; font-size: 15px;">';
            $html .= nl2br(htmlspecialchars($exam->description));
            $html .= '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single section (collapsible)
     */
    private function renderSection(Exam $exam, array $sectionData): string
    {
        $sectionId = $sectionData['id'] ?? null;
        $sectionTitle = $sectionData['title'] ?? 'Unnamed Section';
        $skill = $sectionData['skill'] ?? null;

        // Use HTML5 <details> element for collapsible sections
        $html = '<details open class="exam-section" style="background: #ffffff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.06); border: 1px solid #e5e7eb;">';

        // Section header (clickable summary)
        $html .= '<summary style="cursor: pointer; list-style: none; border-bottom: 2px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 20px; user-select: none;">';
        $html .= '<div style="display: flex; align-items: center; gap: 12px;">';
        $html .= '<div style="flex: 1;">';
        $html .= '<h2 style="margin: 0 0 8px; font-size: 22px; font-weight: 600; color: #111827;">';
        $html .= htmlspecialchars($sectionTitle);
        $html .= '</h2>';
        
        // Section metadata
        $html .= '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
        if ($skill) {
            $html .= $this->renderBadge($skill, 'skill');
        }
        if (isset($sectionData['duration_min'])) {
            $html .= $this->renderBadge($sectionData['duration_min'] . ' min', 'time');
        }
        if (isset($sectionData['max_score'])) {
            $html .= $this->renderBadge('Max: ' . $sectionData['max_score'] . ' pts', 'score');
        }
        $html .= '</div>';
        $html .= '</div>'; // close flex container
        $html .= '<span class="section-toggle-icon" style="font-size: 18px; color: #6b7280;">▶</span>'; // Triangle icon
        $html .= '</div>'; // close summary content wrapper
        $html .= '</summary>';

        // Section content (collapsible)
        $html .= '<div style="padding-top: 20px;">';

        // Questions
        $category = $this->findCategory($exam, $sectionId);
        $assemblyMode = $sectionData['assembly']['mode'] ?? 'inline';

        $html .= $this->renderSectionQuestions($exam, $category, $sectionData, $assemblyMode);

        $html .= '</div>'; // close content wrapper
        $html .= '</details>';

        return $html;
    }

    /**
     * Render questions for a section based on assembly mode
     */
    private function renderSectionQuestions(Exam $exam, ?ExamCategory $category, array $sectionData, string $assemblyMode): string
    {
        // Determine data source: generated questions or examples (with eager loading for question groups)
        $generatedQuestions = $category ? $category->questions()->with('questionGroup')->orderBy('order')->get() : collect();
        $exampleQuestions = $category ? $category->examples()->get() : collect();

        $dataSource = null;
        $questions = collect();

        if ($generatedQuestions->isNotEmpty()) {
            $dataSource = 'generated';
            $questions = $generatedQuestions;
        } elseif ($exampleQuestions->isNotEmpty()) {
            $dataSource = 'examples';
            $questions = $exampleQuestions;
        }

        $html = '';

        // Assembly mode explanation - show before data source
        $html .= $this->renderAssemblyModeExplanation($assemblyMode, $sectionData, $dataSource, $questions);

        // Render questions based on assembly mode
        if ($assemblyMode === 'inline') {
            $html .= $this->renderInlineQuestions($questions, $dataSource);
        } elseif ($assemblyMode === 'blueprint') {
            $html .= $this->renderBlueprintQuestions($sectionData, $questions, $dataSource);
        } elseif ($assemblyMode === 'pool') {
            $html .= $this->renderPoolQuestions($sectionData, $questions, $dataSource);
        } else {
            $html .= $this->renderEmptyState('Неизвестный assembly mode: ' . $assemblyMode);
        }

        return $html;
    }

    /**
     * Render assembly mode explanation
     */
    private function renderAssemblyModeExplanation(string $assemblyMode, array $sectionData, ?string $dataSource, $questions): string
    {
        $expectedCount = $sectionData['expected_task_count']['target'] ?? null;
        $questionsCount = $questions->count();

        $html = '';

        // Get assembly config for more details
        $assemblyConfig = $sectionData['assembly'] ?? [];

        if ($assemblyMode === 'pool') {
            // Pool mode explanation
            $sourceLabel = $dataSource === 'generated' ? 'сгенерированных вопросов' : ($dataSource === 'examples' ? 'примеров вопросов' : 'вопросов');

            $html .= '<div style="margin-bottom: 16px; padding: 16px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 4px solid #f59e0b; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">';
            $html .= '<div style="display: flex; align-items: start; gap: 12px;">';
            $html .= '<div style="font-size: 24px; line-height: 1;">🎲</div>';
            $html .= '<div style="flex: 1;">';
            $html .= '<h4 style="margin: 0 0 8px; font-size: 15px; font-weight: 600; color: #92400e;">Режим пула (Pool Mode)</h4>';
            $html .= '<p style="margin: 0 0 6px; font-size: 14px; color: #78350f; line-height: 1.5;">';
            $html .= '<strong>Выбран режим Pool,</strong> потому что секция содержит однотипные задания с одинаковым форматом ответа.';
            $html .= '</p>';

            if ($expectedCount && $questionsCount > 0) {
                $html .= '<p style="margin: 0; font-size: 14px; color: #78350f; line-height: 1.5;">';
                $html .= '📊 <strong>Источник данных:</strong> ' . $questionsCount . ' ' . $sourceLabel . '<br>';
                $html .= '🎯 <strong>Генерация:</strong> Из пула будет выбрано <strong>' . $expectedCount . ' ' . $this->pluralize($expectedCount, 'задание', 'задания', 'заданий') . '</strong> случайным образом';
                $html .= '</p>';
            }

            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';

        } elseif ($assemblyMode === 'blueprint') {
            // Blueprint mode explanation
            $sourceLabel = $dataSource === 'generated' ? 'сгенерированных вопросов' : ($dataSource === 'examples' ? 'примеров вопросов' : 'вопросов');
            $blueprint = $assemblyConfig['blueprint'] ?? [];
            $slotsCount = count($blueprint);

            $html .= '<div style="margin-bottom: 16px; padding: 16px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-left: 4px solid #3b82f6; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">';
            $html .= '<div style="display: flex; align-items: start; gap: 12px;">';
            $html .= '<div style="font-size: 24px; line-height: 1;">🏗️</div>';
            $html .= '<div style="flex: 1;">';
            $html .= '<h4 style="margin: 0 0 8px; font-size: 15px; font-weight: 600; color: #1e40af;">Режим чертежа (Blueprint Mode)</h4>';
            $html .= '<p style="margin: 0 0 6px; font-size: 14px; color: #1e3a8a; line-height: 1.5;">';
            $html .= '<strong>Выбран режим Blueprint,</strong> потому что секция содержит разнородные типы заданий с точными квотами и пропорциями.';
            $html .= '</p>';

            if ($expectedCount && $questionsCount > 0) {
                $html .= '<p style="margin: 0; font-size: 14px; color: #1e3a8a; line-height: 1.5;">';
                $html .= '📊 <strong>Источник данных:</strong> ' . $questionsCount . ' ' . $sourceLabel . '<br>';
                $html .= '🎯 <strong>Генерация:</strong> По чертежу (' . $slotsCount . ' ' . $this->pluralize($slotsCount, 'слот', 'слота', 'слотов') . ') будет сгенерировано <strong>' . $expectedCount . ' ' . $this->pluralize($expectedCount, 'задание', 'задания', 'заданий') . '</strong>';
                $html .= '</p>';
            }

            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';

        } elseif ($assemblyMode === 'inline') {
            // Inline mode explanation
            $sourceLabel = $dataSource === 'generated' ? 'сгенерированных заданий' : ($dataSource === 'examples' ? 'примеров заданий' : 'заданий');

            $html .= '<div style="margin-bottom: 16px; padding: 16px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">';
            $html .= '<div style="display: flex; align-items: start; gap: 12px;">';
            $html .= '<div style="font-size: 24px; line-height: 1;">✨</div>';
            $html .= '<div style="flex: 1;">';
            $html .= '<h4 style="margin: 0 0 8px; font-size: 15px; font-weight: 600; color: #065f46;">Режим уникальных заданий (Inline Mode)</h4>';
            $html .= '<p style="margin: 0 0 6px; font-size: 14px; color: #064e3b; line-height: 1.5;">';
            $html .= '<strong>Выбран режим Inline,</strong> потому что каждое задание уникально и требует индивидуальной генерации (эссе, описание графика, ролевая игра).';
            $html .= '</p>';

            if ($questionsCount > 0) {
                $html .= '<p style="margin: 0; font-size: 14px; color: #064e3b; line-height: 1.5;">';
                $html .= '📊 <strong>Источник данных:</strong> ' . $questionsCount . ' ' . $sourceLabel . '<br>';
                $html .= '🎯 <strong>Генерация:</strong> Каждое задание генерируется индивидуально с уникальным контентом';
                $html .= '</p>';
            }

            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render inline questions (all tasks from section)
     * Supports both grouped (question_groups) and ungrouped questions
     */
    private function renderInlineQuestions($questions, ?string $dataSource): string
    {
        if ($questions->isEmpty()) {
            return $this->renderEmptyState('Нет вопросов для отображения');
        }

        $html = '<div class="questions-list">';

        // Group questions by question_group_id
        $grouped = $questions->groupBy('question_group_id');
        $questionNumber = 1;

        foreach ($grouped as $questionGroupId => $groupQuestions) {
            if (is_null($questionGroupId)) {
                // Ungrouped questions (existing behavior)
                foreach ($groupQuestions as $question) {
                    $html .= $this->renderQuestion($question, $questionNumber, $dataSource);
                    $questionNumber++;
                }
            } else {
                // Grouped questions (new behavior)
                $questionGroup = $groupQuestions->first()->questionGroup;
                if ($questionGroup) {
                    $html .= $this->renderQuestionGroup($questionGroup, $groupQuestions, $questionNumber, $dataSource);
                    $questionNumber += $groupQuestions->count();
                }
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a question group with shared stimulus and playback controls
     */
    private function renderQuestionGroup(QuestionGroup $questionGroup, $questions, int $startNumber, ?string $dataSource): string
    {
        $html = '<div class="question-group" data-group-id="' . htmlspecialchars($questionGroup->group_id) . '" style="background: #ffffff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.06); border: 2px solid #e5e7eb;">';

        // Group header
        $html .= '<div class="question-group-header" style="border-bottom: 2px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 20px;">';
        $html .= '<h3 style="margin: 0 0 8px; font-size: 20px; font-weight: 600; color: #111827;">';
        $html .= htmlspecialchars($questionGroup->title);
        $html .= '</h3>';

        // Group metadata
        $html .= '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
        $html .= $this->renderBadge($questions->count() . ' ' . $this->pluralize($questions->count(), 'вопрос', 'вопроса', 'вопросов'), 'score');

        $metadata = $questionGroup->metadata ?? [];
        if (isset($metadata['total_points'])) {
            $html .= $this->renderBadge($metadata['total_points'] . ' pts', 'score');
        }
        if (isset($metadata['suggested_time_sec'])) {
            $minutes = ceil($metadata['suggested_time_sec'] / 60);
            $html .= $this->renderBadge($minutes . ' мин', 'time');
        }
        $html .= '</div>';
        $html .= '</div>';

        // Group instructions (if any)
        $instructions = $questionGroup->instructions ?? [];
        if (isset($instructions['brief'])) {
            $html .= '<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 4px; margin-bottom: 16px;">';
            $html .= '<p style="margin: 0; font-size: 14px; color: #92400e; font-weight: 500;">';
            $html .= '📋 ' . $instructions['brief'];
            $html .= '</p>';
            $html .= '</div>';
        }

        // Group stimulus (ОДИН РАЗ для всей группы)
        $html .= $this->renderGroupStimulus($questionGroup);

        // Questions
        $html .= '<div class="question-group-questions" style="margin-top: 20px;">';
        foreach ($questions as $index => $question) {
            $html .= $this->renderGroupedQuestion($question, $startNumber + $index, $dataSource);
        }
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render group stimulus (text, audio, images, video)
     */
    private function renderGroupStimulus(QuestionGroup $questionGroup): string
    {
        $html = '<div class="question-group-stimulus">';

        $stimulus = $questionGroup->stimulus ?? [];

        // Audio with playback controls
        if (!empty($stimulus['audio'])) {
            $html .= $this->renderGroupAudio($questionGroup, $stimulus['audio']);
        }

        // Text
        if (!empty($stimulus['text_html'])) {
            $html .= '<div style="margin-bottom: 12px; padding: 12px; background: #f9fafb; border-radius: 4px; border: 1px solid #e5e7eb;">';
            $html .= '<div style="font-size: 15px; line-height: 1.6; color: #374151;">';
            $html .= $stimulus['text_html'];
            $html .= '</div>';
            $html .= '</div>';
        }

        // Images
        if (!empty($stimulus['images']) && is_array($stimulus['images'])) {
            $html .= '<div style="margin-bottom: 12px;">';
            foreach ($stimulus['images'] as $image) {
                $imageUrl = is_string($image) ? $image : ($image['url'] ?? '');
                if ($imageUrl) {
                    $html .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="Group stimulus image" style="max-width: 100%; border-radius: 8px; margin-bottom: 8px;">';
                }
            }
            $html .= '</div>';
        }

        // Video
        if (!empty($stimulus['video']) && is_array($stimulus['video'])) {
            $html .= '<div style="margin-bottom: 12px;">';
            foreach ($stimulus['video'] as $video) {
                $videoUrl = is_string($video) ? $video : ($video['url'] ?? '');
                if ($videoUrl) {
                    $html .= '<video controls style="width: 100%; border-radius: 8px; margin-bottom: 8px;">';
                    $html .= '<source src="' . htmlspecialchars($videoUrl) . '" type="video/mp4">';
                    $html .= '</video>';
                }
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render group audio with playback controls
     */
    private function renderGroupAudio(QuestionGroup $questionGroup, array $audioUrls): string
    {
        $playbackSettings = $questionGroup->playback_settings ?? [];
        $maxPlays = $playbackSettings['max_plays'] ?? null;
        $enforcement = $playbackSettings['enforcement'] ?? 'none';
        $showCounter = $playbackSettings['show_counter'] ?? true;

        $uniqueId = 'audio-' . uniqid();

        $html = '<div class="audio-player-container" id="' . $uniqueId . '" ';
        $html .= 'data-max-plays="' . ($maxPlays ?? 'unlimited') . '" ';
        $html .= 'data-enforcement="' . htmlspecialchars($enforcement) . '" ';
        $html .= 'data-show-counter="' . ($showCounter ? 'true' : 'false') . '" ';
        $html .= 'style="margin-bottom: 16px; padding: 16px; background: #f0fdf4; border: 2px solid #10b981; border-radius: 8px;">';

        // Playback counter
        if ($showCounter && !is_null($maxPlays)) {
            $html .= '<div class="playback-counter" style="margin-bottom: 12px; font-size: 14px; font-weight: 600; color: #065f46;">';
            $html .= '🎧 Осталось прослушиваний: <span class="plays-left">' . $maxPlays . '</span>';
            $html .= '</div>';
        }

        // Audio player
        foreach ($audioUrls as $audioUrl) {
            $url = is_string($audioUrl) ? $audioUrl : ($audioUrl['url'] ?? '');
            if ($url) {
                $html .= '<audio controls class="question-group-audio" style="width: 100%; margin-bottom: 8px;">';
                $html .= '<source src="' . htmlspecialchars($url) . '" type="audio/mpeg">';
                $html .= 'Ваш браузер не поддерживает аудио.';
                $html .= '</audio>';
            }
        }

        // Enforcement info
        if (!is_null($maxPlays)) {
            if ($enforcement === 'strict') {
                $html .= '<p style="margin: 8px 0 0; font-size: 13px; color: #dc2626; font-weight: 500;">🔒 Строгий контроль: после ' . $maxPlays . ' прослушивания(й) аудио будет заблокировано</p>';
            } elseif ($enforcement === 'advisory') {
                $html .= '<p style="margin: 8px 0 0; font-size: 13px; color: #f59e0b; font-weight: 500;">⚠️ Рекомендация: рекомендуется прослушать не более ' . $maxPlays . ' раз(а)</p>';
            } else {
                $html .= '<p style="margin: 8px 0 0; font-size: 13px; color: #6b7280;">ℹ️ Указано максимум: ' . $maxPlays . ' прослушивания(й)</p>';
            }
        }

        // JavaScript для контроля воспроизведения
        if (!is_null($maxPlays) && $enforcement === 'strict') {
            $html .= '<script>';
            $html .= $this->getPlaybackControlScript($uniqueId, $maxPlays);
            $html .= '</script>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Generate JavaScript for playback control
     */
    private function getPlaybackControlScript(string $containerId, int $maxPlays): string
    {
        return <<<JS
(function() {
    const container = document.getElementById('{$containerId}');
    if (!container) return;

    const audio = container.querySelector('audio');
    const counter = container.querySelector('.plays-left');
    let playsRemaining = {$maxPlays};
    let hasPlayed = false;

    audio.addEventListener('play', function() {
        if (!hasPlayed) {
            hasPlayed = true;
        }
    });

    audio.addEventListener('ended', function() {
        if (hasPlayed) {
            hasPlayed = false;
            playsRemaining--;

            if (counter) {
                counter.textContent = playsRemaining;
            }

            if (playsRemaining <= 0) {
                audio.controls = false;
                audio.style.pointerEvents = 'none';
                audio.style.opacity = '0.5';

                const warning = document.createElement('div');
                warning.className = 'playback-limit-reached';
                warning.style.cssText = 'color: #dc2626; font-weight: bold; margin-top: 12px; padding: 12px; background: #fee2e2; border-radius: 4px; border: 1px solid #dc2626;';
                warning.textContent = '🚫 Лимит прослушиваний исчерпан';
                container.appendChild(warning);
            }
        }
    });
})();
JS;
    }

    /**
     * Render a single question within a group (without stimulus)
     */
    private function renderGroupedQuestion(Question $question, int $number, ?string $dataSource): string
    {
        $html = '<div class="grouped-question" data-question-id="' . htmlspecialchars($question->question_id) . '" style="background: #f9fafb; border-radius: 8px; padding: 16px; margin-bottom: 12px; border: 1px solid #e5e7eb;">';

        // Question number
        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">';
        $html .= '<h4 style="margin: 0; font-size: 16px; font-weight: 600; color: #111827;">';
        $html .= $number . '.';
        $html .= '</h4>';
        $html .= $this->renderBadge($question->type, 'type');
        $html .= '</div>';

        // Question instructions (if any additional to group)
        $instructions = $question->instructions ?? [];
        if (isset($instructions['brief'])) {
            $html .= '<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 4px; margin-bottom: 12px;">';
            $html .= '<p style="margin: 0; font-size: 14px; color: #92400e; font-weight: 500;">';
            $html .= '📋 ' . $instructions['brief'];
            $html .= '</p>';
            $html .= '</div>';
        }

        // Interaction (options, etc.) - БЕЗ stimulus
        $html .= $this->renderQuestionInteraction($question);

        // Metadata
        $html .= $this->renderQuestionMetadata($question);

        $html .= '</div>';

        return $html;
    }

    /**
     * Render blueprint questions (templates with expected count)
     */
    private function renderBlueprintQuestions(array $sectionData, $questions, ?string $dataSource): string
    {
        // Show all available templates/questions
        if ($questions->isEmpty()) {
            return $this->renderEmptyState('Нет шаблонов для отображения');
        }

        $html = '<div class="questions-list">';

        foreach ($questions as $index => $question) {
            $html .= $this->renderQuestion($question, $index + 1, $dataSource, true);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render pool questions (show all from pool with selection indicator)
     */
    private function renderPoolQuestions(array $sectionData, $questions, ?string $dataSource): string
    {
        // Show all pool questions
        if ($questions->isEmpty()) {
            return $this->renderEmptyState('Пул вопросов пуст');
        }

        $html = '<div class="questions-list">';

        foreach ($questions as $index => $question) {
            $html .= $this->renderQuestion($question, $index + 1, $dataSource);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single question (Question or ExamExampleQuestion)
     */
    private function renderQuestion($question, int $number, ?string $dataSource, bool $isTemplate = false): string
    {
        // Determine if it's a generated Question or ExamExampleQuestion
        $isGenerated = $question instanceof Question;

        $html = '<div class="exam-question" style="background: #f9fafb; border-radius: 8px; padding: 20px; margin-bottom: 16px; border: 1px solid #e5e7eb;">';

        // Question number and type
        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">';
        $html .= '<h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827;">';
        $html .= 'Задание ' . $number;
        if ($isTemplate) {
            $html .= ' <span style="font-size: 12px; color: #6b7280;">(шаблон)</span>';
        }
        $html .= '</h3>';

        $type = $isGenerated ? $question->type : ($question->type?->value ?? 'Unknown');
        $html .= $this->renderBadge($type, 'type');
        $html .= '</div>';

        // Render based on type
        if ($isGenerated) {
            $html .= $this->renderGeneratedQuestion($question);
        } else {
            $html .= $this->renderExampleQuestion($question);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render generated Question (v2 format)
     */
    private function renderGeneratedQuestion(Question $question): string
    {
        $html = '';

        // Instructions
        $instructions = $question->instructions ?? [];
        if (isset($instructions['brief'])) {
            $html .= '<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 4px; margin-bottom: 12px;">';
            $html .= '<p style="margin: 0; font-size: 14px; color: #92400e; font-weight: 500;">';
            $html .= '📋 ' . $instructions['brief'];
            $html .= '</p>';
            $html .= '</div>';
        }

        // Stimulus (text, images, audio)
        $stimulus = $question->stimulus ?? [];
        if (isset($stimulus['text_html']) && $stimulus['text_html']) {
            $html .= '<div style="margin-bottom: 12px; padding: 12px; background: #ffffff; border-radius: 4px; border: 1px solid #e5e7eb;">';
            $html .= '<div style="font-size: 15px; line-height: 1.6; color: #374151;">';
            $html .= $stimulus['text_html'];
            $html .= '</div>';
            $html .= '</div>';
        }

        // Images
        if (isset($stimulus['images']) && is_array($stimulus['images']) && !empty($stimulus['images'])) {
            $html .= '<div style="margin-bottom: 12px;">';
            foreach ($stimulus['images'] as $image) {
                $imageUrl = is_string($image) ? $image : ($image['url'] ?? '');
                if ($imageUrl) {
                    $html .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="Question image" style="max-width: 100%; border-radius: 8px; margin-bottom: 8px;">';
                }
            }
            $html .= '</div>';
        }

        // Audio
        if (isset($stimulus['audio']) && is_array($stimulus['audio']) && !empty($stimulus['audio'])) {
            $html .= '<div style="margin-bottom: 12px;">';
            foreach ($stimulus['audio'] as $audio) {
                $audioUrl = is_string($audio) ? $audio : ($audio['url'] ?? '');
                if ($audioUrl) {
                    $html .= '<audio controls style="width: 100%; margin-bottom: 8px;">';
                    $html .= '<source src="' . htmlspecialchars($audioUrl) . '" type="audio/mpeg">';
                    $html .= '</audio>';
                }
            }
            $html .= '</div>';
        }

        // Interaction (options, answers)
        $html .= $this->renderQuestionInteraction($question);

        // Metadata
        $html .= $this->renderQuestionMetadata($question);

        return $html;
    }

    /**
     * Render ExamExampleQuestion
     */
    private function renderExampleQuestion(ExamExampleQuestion $question): string
    {
        $html = '';

        // Instructions
        if ($question->instructions) {
            $html .= '<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 4px; margin-bottom: 12px;">';
            $html .= '<p style="margin: 0; font-size: 14px; color: #92400e; font-weight: 500;">';
            $html .= '📋 ' . $question->instructions;
            $html .= '</p>';
            $html .= '</div>';
        }

        // Question text
        if ($question->question) {
            $html .= '<div style="margin-bottom: 12px; padding: 12px; background: #ffffff; border-radius: 4px; border: 1px solid #e5e7eb;">';
            $html .= '<div style="font-size: 15px; line-height: 1.6; color: #374151;">';
            $html .= $question->question;
            $html .= '</div>';
            $html .= '</div>';
        }

        // Options (from payload)
        $html .= $this->renderExampleQuestionOptions($question);

        // Assessment guide
        if ($question->assessment_guide) {
            $html .= '<div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 12px; border-radius: 4px; margin-top: 12px;">';
            $html .= '<p style="margin: 0; font-size: 13px; color: #065f46; font-weight: 500;">';
            $html .= '✅ ' . $question->assessment_guide;
            $html .= '</p>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render question interaction (options, etc.) for generated questions
     */
    private function renderQuestionInteraction(Question $question): string
    {
        $interaction = $question->interaction ?? [];
        $responseType = $interaction['response_type'] ?? null;

        $html = '';

        // Handle different response types
        if (in_array($responseType, ['selection'], true)) {
            $options = $interaction['options'] ?? [];
            if (!empty($options)) {
                $html .= '<div style="margin-top: 12px;">';
                $html .= '<ol style="list-style: none; padding: 0; margin: 0;">';

                foreach ($options as $option) {
                    $label = is_array($option) ? ($option['text'] ?? $option['label'] ?? '') : $option;
                    $optionId = is_array($option) ? ($option['id'] ?? '') : '';

                    // Check if correct answer
                    $isCorrect = $this->isCorrectAnswer($question, $optionId);

                    $bgColor = $isCorrect ? '#ecfdf5' : '#ffffff';
                    $borderColor = $isCorrect ? '#10b981' : '#e5e7eb';

                    $html .= '<li style="margin-bottom: 8px; padding: 10px 12px; background: ' . $bgColor . '; border: 1px solid ' . $borderColor . '; border-radius: 6px;">';
                    if ($isCorrect) {
                        $html .= '<span style="color: #10b981; font-weight: bold; margin-right: 8px;">✓</span>';
                    }
                    $html .= '<span style="color: #374151;">' . htmlspecialchars($label) . '</span>';
                    $html .= '</li>';
                }

                $html .= '</ol>';
                $html .= '</div>';
            }
        }

        return $html;
    }

    /**
     * Render options for ExamExampleQuestion
     */
    private function renderExampleQuestionOptions(ExamExampleQuestion $question): string
    {
        $payload = $question->payload ?? [];
        $type = $question->type?->value;

        $html = '';

        // Handle selection-based questions
        if (in_array($type, ['single_select', 'multi_select'], true)) {
            $options = $payload['options'] ?? [];
            if (!empty($options)) {
                $html .= '<div style="margin-top: 12px;">';
                $html .= '<ol style="list-style: none; padding: 0; margin: 0;">';

                foreach ($options as $idx => $option) {
                    $label = is_array($option) ? ($option['text'] ?? $option['label'] ?? '') : $option;

                    // Check if correct answer
                    $isCorrect = false;
                    if (isset($payload['correct_answer'])) {
                        $correctAnswer = $payload['correct_answer'];
                        if (is_array($correctAnswer)) {
                            $isCorrect = in_array($idx, $correctAnswer, true) || in_array((string) $idx, $correctAnswer, true);
                        } else {
                            $isCorrect = $idx === $correctAnswer || (string) $idx === $correctAnswer;
                        }
                    }

                    $bgColor = $isCorrect ? '#ecfdf5' : '#ffffff';
                    $borderColor = $isCorrect ? '#10b981' : '#e5e7eb';

                    $html .= '<li style="margin-bottom: 8px; padding: 10px 12px; background: ' . $bgColor . '; border: 1px solid ' . $borderColor . '; border-radius: 6px;">';
                    if ($isCorrect) {
                        $html .= '<span style="color: #10b981; font-weight: bold; margin-right: 8px;">✓</span>';
                    }
                    $html .= '<span style="color: #374151;">' . htmlspecialchars($label) . '</span>';
                    $html .= '</li>';
                }

                $html .= '</ol>';
                $html .= '</div>';
            }
        }

        return $html;
    }

    /**
     * Render question metadata (duration, score, etc.)
     */
    private function renderQuestionMetadata(Question $question): string
    {
        $metadata = $question->metadata ?? [];
        $html = '';

        $items = [];

        if ($question->time_limit_sec) {
            $minutes = ceil($question->time_limit_sec / 60);
            $items[] = '⏱ ' . $minutes . ' мин';
        }

        $scoring = $question->scoring ?? [];
        if (isset($scoring['max_score'])) {
            $items[] = '📊 Max: ' . $scoring['max_score'] . ' pts';
        }

        if (isset($metadata['difficulty'])) {
            $items[] = '🎯 ' . ucfirst($metadata['difficulty']);
        }

        if (!empty($items)) {
            $html .= '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; color: #6b7280;">';
            foreach ($items as $item) {
                $html .= '<span>' . htmlspecialchars($item) . '</span>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Check if option is correct answer
     */
    private function isCorrectAnswer(Question $question, string $optionId): bool
    {
        $scoring = $question->scoring ?? [];
        $answerKey = $scoring['answer_key'] ?? null;

        if (!$answerKey) {
            return false;
        }

        if (is_array($answerKey)) {
            return in_array($optionId, $answerKey, true);
        }

        return $answerKey === $optionId;
    }

    /**
     * Find ExamCategory by section ID
     */
    private function findCategory(Exam $exam, ?string $sectionId): ?ExamCategory
    {
        if (!$sectionId) {
            return null;
        }

        return $exam->categories()->where('key', $sectionId)->first();
    }

    /**
     * Render a badge
     */
    private function renderBadge(string $text, string $type = 'default'): string
    {
        $colors = [
            'level' => ['bg' => '#ecfdf3', 'border' => '#16a34a', 'text' => '#15803d'],
            'language' => ['bg' => '#eef2ff', 'border' => '#4f46e5', 'text' => '#4338ca'],
            'provider' => ['bg' => '#f3f4f6', 'border' => '#6b7280', 'text' => '#374151'],
            'skill' => ['bg' => '#eef2ff', 'border' => '#4f46e5', 'text' => '#4338ca'],
            'time' => ['bg' => '#fef3c7', 'border' => '#f59e0b', 'text' => '#92400e'],
            'score' => ['bg' => '#ecfdf5', 'border' => '#10b981', 'text' => '#065f46'],
            'type' => ['bg' => '#ecfeff', 'border' => '#06b6d4', 'text' => '#0e7490'],
            'default' => ['bg' => '#f3f4f6', 'border' => '#e5e7eb', 'text' => '#374151'],
        ];

        $color = $colors[$type] ?? $colors['default'];

        return '<span style="display: inline-block; font-size: 12px; padding: 4px 8px; border-radius: 999px; background: ' . $color['bg'] . '; color: ' . $color['text'] . '; border: 1px solid ' . $color['border'] . '; font-weight: 600;">' . htmlspecialchars($text) . '</span>';
    }

    /**
     * Render empty state message
     */
    private function renderEmptyState(string $message): string
    {
        return '<div style="padding: 24px; text-align: center; background: #f9fafb; border-radius: 8px; border: 2px dashed #d1d5db; color: #6b7280; font-size: 14px;">' . htmlspecialchars($message) . '</div>';
    }

    /**
     * Pluralize Russian words
     */
    private function pluralize(int $count, string $one, string $few, string $many): string
    {
        $count = abs($count);
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }
}
