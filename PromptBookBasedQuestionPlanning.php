<?php

namespace App\Services\MagicGeneration\BookBasedGeneration;

use App\Models\Category;
use JsonException;

class PromptBookBasedQuestionPlanning
{
    private static function detectContentSource(string $content): string
    {
        return str_contains($content, '--- Web Research Content for') ? 'web' : 'book';
    }

    /**
     * @throws JsonException
     */
    public static function buildPrompt(
        string $examShortDescription,
        string $examInfo,
        Category $currentCategoryObject,
        ?Category $parentCategoryObject,
        string $bookContent,
        int $batchSize,
        bool $shouldUseTables = false,
        bool $shouldHaveFormulas = false,
        array $existingPlans = [],
        array $archetypeData = []
    ): string {
        $existingPlans = [];//TODO REMOVE
        $currentCategory = $currentCategoryObject->name;
        $parentCategory = $parentCategoryObject?->name;

        $categoryContext = $currentCategory;
        if ($parentCategory && $parentCategory !== $currentCategory) {
            $categoryContext = "{$parentCategory} в†’ {$currentCategory}";
        }

        $categoriesData = [
            'current_category' => [
                'name' => $currentCategoryObject->name,
                'description' => $currentCategoryObject->description ?? ''
            ]
        ];

        if ($parentCategoryObject) {
            $categoriesData['parent_category'] = [
                'name' => $parentCategoryObject->name,
                'description' => $parentCategoryObject->description ?? ''
            ];
        }

        $categoriesDataJson = json_encode($categoriesData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $contentSource = self::detectContentSource($bookContent);
        $contentTag = $contentSource === 'web' ? 'researched_content' : 'study_material';
        $contentDescription = $contentSource === 'web' ?
            'web-researched content specific to this category' :
            'book chapters and study material';

        $tableGuidance = '';
        if ($shouldUseTables) {
            $tableGuidance = <<<EOT

**TABLE USAGE GUIDELINES:**
When planning questions, consider using tables when they would make the question more effective:
- For presenting data that needs to be analyzed or compared
- For organizing complex information that students need to interpret
- For scenarios involving multiple variables or cases
- For questions requiring data synthesis or pattern recognition

If a planned question would benefit from a table, include:
- **uses_table**: true
- **table_purpose**: Brief description of what the table will contain and why it's needed
- **table_data_type**: Type of data (e.g., "clinical scenarios", "research data", "comparative analysis", "diagnostic criteria")

Tables should enhance the question's educational value, not just display information that could be presented as text.
EOT;
        }

        $formulaGuidance = '';
        if ($shouldHaveFormulas) {
            $formulaGuidance = <<<EOT

**MATHEMATICAL FORMULA GUIDELINES:**
When planning questions that involve mathematical content, consider if they need LaTeX formulas:

**Use LaTeX formulas when questions involve:**
- Complex equations (e.g., kinetic equations, thermodynamic formulas)
- Chemical reactions with complex stoichiometry
- Mathematical expressions with fractions, exponents, or special symbols
- Scientific notations that require proper formatting
- Multi-step calculations that need clear mathematical representation
- ANY expressions with exponents (like x^2, 10^3, (6)^n) that would use ^ symbol
- Expressions with subscripts in mathematical contexts (beyond simple chemical formulas)

**DO NOT use LaTeX for:**
- Simple chemical formulas (Hв‚‚O, COв‚‚, etc.) when used in basic chemical context
- Basic numbers or percentages without mathematical operations
- Simple units or measurements

**IMPORTANT:** Be generous with LaTeX planning - it's better to plan for LaTeX and not need it than to have poor formatting with ^ symbols or unclear mathematical expressions.

If a planned question needs LaTeX formulas, include:
- **uses_latex**: true
- **latex_type**: "inline" (within text) or "standalone" (on separate lines)
- **formula_purpose**: Brief description of what mathematical content will be displayed

Examples:
- Complex equation: uses_latex: true, latex_type: "standalone"
- Inline formula in text: uses_latex: true, latex_type: "inline"
- Mathematical expressions with exponents: uses_latex: true
EOT;
        }

        $imageGuidance = '';
        if ($currentCategoryObject->should_have_images) {
            $imageGuidance = <<<EOT

**IMAGE-INTEGRATED QUESTION PLANNING (MANDATORY):**
Every planned question must rely on an image to be answerable.

For each plan include:
- **image_required**: true
- **image_description**: Concrete description of a single, simple photo or realistic 3D render
- **image_style**: one of ["photo","3d_render","illustration"] - specify the visual style for generation
- **image_use_in_stem**: one of ["identify","measure","compare","locate","sequence","diagnose","infer_process"]
- **image_answer_dependency**: Exact visual element that enables the correct answer
- **image_visual_cues**: 2вЂ“5 cues the image must contain
- **image_distractor_strategy**: How wrong options map to plausible misreads of the image
- **image_validation**: One-line checklist proving the item cannot be answered without the image

**Hard rules:**
- IMPORTANT: No text, numbers, labels, arrows, logos, or UI in the image
- Single subject, minimal background, stable viewpoint
- Educational accuracy of shapes, proportions, and orientation
- No schematic/infographic images such as flowcharts, sequence diagrams, class/UML diagrams etc
- No data charts/plots such as bar/column, line/area/step, scatter, bubble, histogram etc

**Good patterns:**
- Identify: вЂњWhich structure is highlighted/positionedвЂ¦вЂќ
- Measure: вЂњBased on the monitor reading/scale shownвЂ¦вЂќ
- Compare: вЂњWhich option best matches the picturedвЂ¦вЂќ
- Locate: вЂњWhere does X drain/attach relative to the imageвЂ¦вЂќ
- Sequence: вЂњWhich step comes next given the setup shownвЂ¦вЂќ
- Diagnose: вЂњWhich condition is most consistent with the findingвЂ¦вЂќ

EOT;
        }

        $existingPlansSection = '';
        if (!empty($existingPlans)) {
            $existingPlansJson = json_encode($existingPlans, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $existingPlansSection = <<<EOT

<existing_plans>
{$existingPlansJson}
</existing_plans>
EOT;
        }

        $archetypeGuidance = '';
        if (!empty($archetypeData)) {
            $archetypes = $archetypeData['archetypes'] ?? [];
            $difficultyFocus = $archetypeData['difficulty_focus'] ?? 'hard';

            if (!empty($archetypes)) {
                $archetypesJson = json_encode($archetypes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $archetypeGuidance = <<<EOT

<question_archetypes>
{$archetypesJson}
</question_archetypes>
EOT;

                $archetypeGuidance .= <<<EOT

**ARCHETYPE-DRIVEN PLANNING GUIDANCE:**
Use the provided question archetypes to guide your planning. These archetypes represent proven question patterns from real exams:

**DIFFICULTY TARGETING:**
- Focus on **{$difficultyFocus}** difficulty questions
- Prioritize archetypes marked as "hard" difficulty
- Avoid "easy" difficulty patterns - we want challenging questions that test deep understanding

**ARCHETYPE UTILIZATION:**
- **Distribute plans across multiple archetypes** - don't use just one pattern
- **Leverage archetype weights** - higher-weighted archetypes should appear more frequently in your plans
- **Use stem templates** as inspiration for question approaches
- **Apply skills measured** to define your difficulty_focus and question_approach
- **Consider common distractors** when planning questions that will need challenging wrong answers

**QUESTION APPROACH GUIDANCE:**
For each planned question, consider which archetype pattern(s) it follows:
- Select archetype-appropriate cognitive skills (analysis, synthesis, evaluation, etc.)
- Use archetype stem templates to inspire question structures
- Plan for archetype-specific distractor patterns
- Consider archetype difficulty bands when setting challenge levels

**ARCHETYPE DISTRIBUTION STRATEGY:**
- Use multiple different archetypes across your {$batchSize} planned questions
- Weight your selections based on the archetype category weights provided
- Ensure variety in question approaches while maintaining high difficulty
- Focus on archetypes that enable complex reasoning and application

EOT;
            }
        }

        return <<<EOT
<exam_short_description>
{$examShortDescription}
</exam_short_description>
<exam_info>
{$examInfo}
</exam_info>
<categories_description>
{$categoriesDataJson}
</categories_description>
<{$contentTag}>
{$bookContent}
</{$contentTag}>
<current_category>
{$categoryContext}
</current_category>{$existingPlansSection}{$archetypeGuidance}
<task>
You are tasked with creating a detailed plan for generating {$batchSize} challenging exam questions based on the provided {$contentDescription}.

**CRITICAL REQUIREMENTS:**
1. **Analyze {$contentDescription} thoroughly**: Identify key concepts, complex procedures, advanced theories, and challenging scenarios
2. **Focus on high-difficulty questions**: Prioritize analysis, synthesis, and complex application questions that test deep understanding
3. **Ensure comprehensive coverage**: Plan questions that cover different aspects of the material while maintaining high difficulty
4. **Create specific question concepts**: Each planned question should have a clear focus and learning objective
5. **Avoid repetition**: If existing plans are provided, ensure your new plans cover different concepts and approaches{$tableGuidance}{$formulaGuidance}{$imageGuidance}

**PLANNING INSTRUCTIONS:**
First, carefully analyze the {$contentDescription} and identify:
- Complex concepts that require deep understanding
- Procedures or processes that can be tested through challenging scenarios
- Relationships between different concepts that can be synthesized
- Advanced applications that require critical thinking
- Case studies or examples that can be expanded into difficult questions

Then, create a detailed plan for {$batchSize} questions. For each planned question, specify:
- **concept**: The main concept or topic to be tested
- **difficulty_focus**: The type of cognitive challenge (analysis, synthesis, complex_application, evaluation, critical_thinking, etc.)
- **question_approach**: How the question will test understanding (scenario-based, case study, comparison, problem-solving, etc.)
- **key_content**: Include 1-2 critical sentences or key phrases directly from the source material that are essential for this question, along with specific content references (keep concise but include verbatim excerpts)
- **challenge_level**: What makes this question particularly challenging


**IMPORTANT GUIDELINES:**
- All questions should be challenging and require deep understanding
- Focus on testing comprehension, analysis, and application rather than memorization
- Ensure questions test material that's actually covered in the provided content
- Plan questions that could realistically appear on a professional certification exam
- Avoid simple definition or recall questions

Create exactly {$batchSize} question plans with detailed analysis.
</task>
EOT;
    }

    public static function getSchema(string $provider): array
    {
        $isGoogle = $provider === 'google';

        $planProperties = [
            'n' => [
                'type' => $isGoogle ? 'INTEGER' : 'integer',
                'description' => 'Question plan number (1, 2, 3, etc.)'
            ],
            'concept' => [
                'type' => $isGoogle ? 'STRING' : 'string',
                'description' => 'The main concept or topic to be tested'
            ],
            'difficulty_focus' => [
                'type' => $isGoogle ? 'STRING' : 'string',
                'enum' => ['analysis', 'synthesis', 'complex_application', 'evaluation', 'critical_thinking'],
                'description' => 'The type of cognitive challenge'
            ],
            'question_approach' => [
                'type' => $isGoogle ? 'STRING' : 'string',
                'description' => 'How the question will test understanding (scenario-based, case study, comparison, problem-solving, etc.)'
            ],
            'key_content' => [
                'type' => $isGoogle ? 'STRING' : 'string',
                'description' => 'Include 1-2 critical sentences or key phrases directly from the source material essential for this question, along with specific content references'
            ],
            'challenge_level' => [
                'type' => $isGoogle ? 'STRING' : 'string',
                'description' => 'What makes this question particularly challenging'
            ],
            'uses_table' => [
                'type' => $isGoogle ? 'BOOLEAN' : 'boolean',
                'description' => 'Whether this question would benefit from a table format'
            ],
            'table_purpose' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'If uses_table is true, describe what the table will contain and why. Otherwise null.'
            ] : [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'null']
                ],
                'description' => 'If uses_table is true, describe what the table will contain and why. Otherwise null.'
            ],
            'table_data_type' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'If uses_table is true, describe the type of data in the table. Otherwise null.'
            ] : [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'null']
                ],
                'description' => 'If uses_table is true, describe the type of data in the table. Otherwise null.'
            ],
            'uses_latex' => [
                'type' => $isGoogle ? 'BOOLEAN' : 'boolean',
                'description' => 'Whether this question requires LaTeX for mathematical formulas'
            ],
            'latex_type' => $isGoogle ? [
                'type' => 'STRING',
                'enum' => ['inline', 'standalone'],
                'description' => 'If uses_latex is true, specify if the formula is inline or standalone. Otherwise null.'
            ] : [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['inline', 'standalone']],
                    ['type' => 'null']
                ],
                'description' => 'If uses_latex is true, specify if the formula is inline or standalone. Otherwise null.'
            ],
            'formula_purpose' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'If uses_latex is true, describe the mathematical content to be displayed. Otherwise null.'
            ] : [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'null']
                ],
                'description' => 'If uses_latex is true, describe the mathematical content to be displayed. Otherwise null.'
            ],
            'image_description' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'A brief description of a graphic illustration, photo, chart, or image suitable for this quiz question. Only required when category has should_have_images=true. Otherwise null.'
            ] : [
                'anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'null']
                ],
                'description' => 'A brief description of a graphic illustration, photo, chart, or image suitable for this quiz question. Only required when category has should_have_images=true. Otherwise null.'
            ],
            'image_required' => [
                'type' => $isGoogle ? 'BOOLEAN' : 'boolean',
                'description' => 'Image is required to answer the question'
            ],
            'image_use_in_stem' => $isGoogle ? [
                'type' => 'STRING',
                'enum' => ['identify','measure','compare','locate','sequence','diagnose','infer_process'],
                'description' => 'How the image will be used in the stem'
            ] : [
                'anyOf' => [['type' => 'string','enum' => ['identify','measure','compare','locate','sequence','diagnose','infer_process']],['type' => 'null']],
                'description' => 'How the image will be used in the stem'
            ],
            'image_answer_dependency' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'Specific visual element that anchors the correct answer'
            ] : [
                'anyOf' => [['type' => 'string'],['type' => 'null']],
                'description' => 'Specific visual element that anchors the correct answer'
            ],
            'image_visual_cues' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'Bullet-like text listing required visible cues'
            ] : [
                'anyOf' => [['type' => 'string'],['type' => 'null']],
                'description' => 'Bullet-like text listing required visible cues'
            ],
            'image_distractor_strategy' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'How distractors map to plausible misreads of the image'
            ] : [
                'anyOf' => [['type' => 'string'],['type' => 'null']],
                'description' => 'How distractors map to plausible misreads of the image'
            ],
            'image_validation' => $isGoogle ? [
                'type' => 'STRING',
                'description' => 'Checklist ensuring the question cannot be answered without the image'
            ] : [
                'anyOf' => [['type' => 'string'],['type' => 'null']],
                'description' => 'Checklist ensuring the question cannot be answered without the image'
            ],
            'image_style' => $isGoogle ? [
                'type' => 'STRING',
                'enum' => ['photo','3d_render','illustration'],
                'description' => 'Visual style for image generation'
            ] : [
                'anyOf' => [['type' => 'string','enum' => ['photo','3d_render','illustration']],['type' => 'null']],
                'description' => 'Visual style for image generation'
            ]
        ];

        $schema = [
            'type' => $isGoogle ? 'OBJECT' : 'object',
            'properties' => [
                'analysis' => [
                    'type' => $isGoogle ? 'STRING' : 'string',
                    'description' => 'Analysis of the provided content identifying key concepts, procedures, and challenging scenarios'
                ],
                'question_plans' => [
                    'type' => $isGoogle ? 'ARRAY' : 'array',
                    'description' => 'Array of detailed question plans',
                    'items' => [
                        'type' => $isGoogle ? 'OBJECT' : 'object',
                        'properties' => $planProperties,
                        'required' => ['n', 'concept', 'difficulty_focus', 'question_approach', 'key_content', 'challenge_level', 'uses_table', 'table_purpose', 'table_data_type', 'uses_latex', 'latex_type', 'formula_purpose', 'image_description', 'image_required', 'image_use_in_stem', 'image_answer_dependency', 'image_visual_cues', 'image_distractor_strategy', 'image_validation', 'image_style']
                    ]
                ]
            ],
            'required' => ['analysis', 'question_plans']
        ];

        if ($isGoogle) {
            return $schema;
        }

        // For OpenAI strict mode, ALL properties must be in the required array
        // Conditional fields use anyOf with null to allow optional values
        // The null coalescing operator (??) in QuestionPlanService handles null values
        $schema['properties']['question_plans']['items']['additionalProperties'] = false;
        $schema['additionalProperties'] = false;

        return [
            'name' => 'question_planning_response',
            'description' => 'Response containing detailed plans for generating exam questions',
            'schema' => $schema,
            'strict' => true
        ];
    }
}