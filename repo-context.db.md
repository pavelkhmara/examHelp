# Complete Repository Context
Generated: Tue, Oct 21, 2025  1:31:30 PM

## 🗄️  DATABASE — Current Schema (live)

_Using: docker compose exec -T app php artisan (Laravel bootstrap + INFORMATION_SCHEMA)._ 

### 📋 Tables


```txt
action_events
attempt_answers
attempts
cache
cache_locks
evaluations
exam_categories
exam_example_questions
exams
failed_jobs
generation_logs
generation_tasks
job_batches
jobs
migrations
model_has_permissions
model_has_roles
nova_field_attachments
nova_notifications
nova_pending_field_attachments
password_reset_tokens
permissions
personal_access_tokens
question_options
questions
role_has_permissions
roles
sessions
users

```

#### Table: `action_events`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | batch_id | char(36) | NO |  | MUL |  |
| 3 | user_id | bigint unsigned | NO |  | MUL |  |
| 4 | name | varchar(255) | NO |  |  |  |
| 5 | actionable_type | varchar(255) | NO |  | MUL |  |
| 6 | actionable_id | char(36) | NO |  |  |  |
| 7 | target_type | varchar(255) | NO |  | MUL |  |
| 8 | target_id | char(36) | YES |  |  |  |
| 9 | model_type | varchar(255) | NO |  |  |  |
| 10 | model_id | char(36) | YES |  |  |  |
| 11 | fields | text | NO |  |  |  |
| 12 | status | varchar(25) | NO | running |  |  |
| 13 | exception | text | NO |  |  |  |
| 14 | created_at | timestamp | YES |  |  |  |
| 15 | updated_at | timestamp | YES |  |  |  |
| 16 | original | mediumtext | YES |  |  |  |
| 17 | changes | mediumtext | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for action_events)_

#### Table: `attempt_answers`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | char(36) | NO |  | PRI |  |
| 2 | attempt_id | char(36) | NO |  | MUL |  |
| 3 | question_id | char(36) | NO |  | MUL |  |
| 4 | selected_option_id | char(36) | YES |  | MUL |  |
| 5 | text_answer | text | YES |  |  |  |
| 6 | is_correct | tinyint(1) | YES |  | MUL |  |
| 7 | created_at | timestamp | YES |  |  |  |
| 8 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| attempt_id | attempts | id | attempt_answers_attempt_id_foreign |
| question_id | questions | id | attempt_answers_question_id_foreign |
| selected_option_id | question_options | id | attempt_answers_selected_option_id_foreign |

#### Table: `attempts`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | char(36) | NO |  | PRI |  |
| 2 | exam_id | char(36) | NO |  | MUL |  |
| 3 | user_id | char(36) | YES |  |  |  |
| 4 | started_at | timestamp | YES |  |  |  |
| 5 | completed_at | timestamp | YES |  | MUL |  |
| 6 | score | int unsigned | YES |  |  |  |
| 7 | created_at | timestamp | YES |  |  |  |
| 8 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| exam_id | exams | id | attempts_exam_id_foreign |

#### Table: `cache`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | key | varchar(255) | NO |  | PRI |  |
| 2 | value | mediumtext | NO |  |  |  |
| 3 | expiration | int | NO |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for cache)_

#### Table: `cache_locks`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | key | varchar(255) | NO |  | PRI |  |
| 2 | owner | varchar(255) | NO |  |  |  |
| 3 | expiration | int | NO |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for cache_locks)_

#### Table: `evaluations`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | int unsigned | NO |  | PRI | auto_increment |
| 2 | user_id | bigint unsigned | YES |  | MUL |  |
| 3 | exam_id | char(36) | NO |  | MUL |  |
| 4 | exam_category_id | int unsigned | YES |  | MUL |  |
| 5 | answer | text | NO |  |  |  |
| 6 | result | json | YES |  |  |  |
| 7 | created_at | timestamp | YES |  |  |  |
| 8 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| exam_category_id | exam_categories | id | evaluations_exam_category_id_foreign |
| exam_id | exams | id | evaluations_exam_id_foreign |
| user_id | users | id | evaluations_user_id_foreign |

#### Table: `exam_categories`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | int unsigned | NO |  | PRI | auto_increment |
| 2 | exam_id | char(36) | NO |  | MUL |  |
| 3 | key | varchar(255) | NO |  | MUL |  |
| 4 | name | varchar(255) | NO |  |  |  |
| 5 | meta | json | YES |  |  |  |
| 6 | created_at | timestamp | YES |  |  |  |
| 7 | updated_at | timestamp | YES |  |  |  |
| 8 | description | text | YES |  |  |  |
| 9 | order | int | NO | 0 |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| exam_id | exams | id | exam_categories_exam_id_foreign |

#### Table: `exam_example_questions`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | int unsigned | NO |  | PRI | auto_increment |
| 2 | exam_id | char(36) | NO |  | MUL |  |
| 3 | exam_category_id | int unsigned | NO |  | MUL |  |
| 4 | question | text | NO |  |  |  |
| 5 | good_answer | json | YES |  |  |  |
| 6 | average_answer | json | YES |  |  |  |
| 7 | bad_answer | json | YES |  |  |  |
| 8 | rubric_breakdown | json | YES |  |  |  |
| 9 | created_at | timestamp | YES |  |  |  |
| 10 | updated_at | timestamp | YES |  |  |  |
| 11 | type | enum('single_select','multi_select','true_false','yes_no_ng','dropdown_cloze','gap_cloze','banked_cloze','matching','order_sentences','order_words','highlight_text','short_answer','numeric','listen_mcq','dictation','error_correction','writing_prompt','speaking_prompt') | NO | single_select |  |  |
| 12 | payload | json | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| exam_category_id | exam_categories | id | exam_example_questions_exam_category_id_foreign |
| exam_id | exams | id | exam_example_questions_exam_id_foreign |

#### Table: `exams`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | char(36) | NO |  | PRI |  |
| 2 | slug | varchar(255) | YES |  | UNI |  |
| 3 | title | varchar(255) | NO |  |  |  |
| 4 | description | text | YES |  |  |  |
| 5 | sources | json | YES |  |  |  |
| 6 | meta | json | YES |  |  |  |
| 7 | research_status | enum('queued','running_overview','running_categories','running_examples','running_rubrics','completed','failed') | NO | queued | MUL |  |
| 8 | categories_count | int unsigned | NO | 0 |  |  |
| 9 | examples_count | int unsigned | NO | 0 |  |  |
| 10 | level | enum('A1','A2','B1','B2','C1','C2') | NO | B1 |  |  |
| 11 | is_active | tinyint(1) | NO | 1 |  |  |
| 12 | created_at | timestamp | YES |  |  |  |
| 13 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for exams)_

#### Table: `failed_jobs`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | uuid | varchar(255) | NO |  | UNI |  |
| 3 | connection | text | NO |  |  |  |
| 4 | queue | text | NO |  |  |  |
| 5 | payload | longtext | NO |  |  |  |
| 6 | exception | longtext | NO |  |  |  |
| 7 | failed_at | timestamp | NO | CURRENT_TIMESTAMP |  | DEFAULT_GENERATED |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for failed_jobs)_

#### Table: `generation_logs`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | exam_id | char(36) | YES |  | MUL |  |
| 3 | generation_task_id | bigint unsigned | NO |  | MUL |  |
| 4 | stage | varchar(255) | YES |  |  |  |
| 5 | request | json | YES |  |  |  |
| 6 | response | json | YES |  |  |  |
| 7 | prompt_tokens | int unsigned | NO | 0 |  |  |
| 8 | completion_tokens | int unsigned | NO | 0 |  |  |
| 9 | total_tokens | int unsigned | NO | 0 |  |  |
| 10 | created_at | timestamp | YES |  |  |  |
| 11 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| exam_id | exams | id | generation_logs_exam_id_foreign |
| generation_task_id | generation_tasks | id | generation_logs_generation_task_id_foreign |

#### Table: `generation_tasks`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | exam_id | char(36) | YES |  | MUL |  |
| 3 | type | varchar(255) | NO |  | MUL |  |
| 4 | subject_type | varchar(255) | YES |  | MUL |  |
| 5 | subject_id | bigint unsigned | YES |  |  |  |
| 6 | status | enum('queued','running','completed','failed') | NO | queued | MUL |  |
| 7 | request | json | YES |  |  |  |
| 8 | response | json | YES |  |  |  |
| 9 | error | text | YES |  |  |  |
| 10 | result | json | YES |  |  |  |
| 11 | attempts | int unsigned | NO | 0 |  |  |
| 12 | created_at | timestamp | YES |  |  |  |
| 13 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| exam_id | exams | id | generation_tasks_exam_id_foreign |

#### Table: `job_batches`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | varchar(255) | NO |  | PRI |  |
| 2 | name | varchar(255) | NO |  |  |  |
| 3 | total_jobs | int | NO |  |  |  |
| 4 | pending_jobs | int | NO |  |  |  |
| 5 | failed_jobs | int | NO |  |  |  |
| 6 | failed_job_ids | longtext | NO |  |  |  |
| 7 | options | mediumtext | YES |  |  |  |
| 8 | cancelled_at | int | YES |  |  |  |
| 9 | created_at | int | NO |  |  |  |
| 10 | finished_at | int | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for job_batches)_

#### Table: `jobs`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | queue | varchar(255) | NO |  | MUL |  |
| 3 | payload | longtext | NO |  |  |  |
| 4 | attempts | tinyint unsigned | NO |  |  |  |
| 5 | reserved_at | int unsigned | YES |  |  |  |
| 6 | available_at | int unsigned | NO |  |  |  |
| 7 | created_at | int unsigned | NO |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for jobs)_

#### Table: `migrations`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | int unsigned | NO |  | PRI | auto_increment |
| 2 | migration | varchar(255) | NO |  |  |  |
| 3 | batch | int | NO |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for migrations)_

#### Table: `model_has_permissions`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | permission_id | bigint unsigned | NO |  | PRI |  |
| 2 | model_type | varchar(255) | NO |  | PRI |  |
| 3 | model_id | bigint unsigned | NO |  | PRI |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| permission_id | permissions | id | model_has_permissions_permission_id_foreign |

#### Table: `model_has_roles`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | role_id | bigint unsigned | NO |  | PRI |  |
| 2 | model_type | varchar(255) | NO |  | PRI |  |
| 3 | model_id | bigint unsigned | NO |  | PRI |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| role_id | roles | id | model_has_roles_role_id_foreign |

#### Table: `nova_field_attachments`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | int unsigned | NO |  | PRI | auto_increment |
| 2 | attachable_type | varchar(255) | NO |  | MUL |  |
| 3 | attachable_id | bigint unsigned | NO |  |  |  |
| 4 | attachment | varchar(255) | NO |  |  |  |
| 5 | disk | varchar(255) | NO |  |  |  |
| 6 | url | varchar(255) | NO |  | MUL |  |
| 7 | created_at | timestamp | YES |  |  |  |
| 8 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for nova_field_attachments)_

#### Table: `nova_notifications`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | char(36) | NO |  | PRI |  |
| 2 | type | varchar(255) | NO |  |  |  |
| 3 | notifiable_type | varchar(255) | NO |  | MUL |  |
| 4 | notifiable_id | bigint unsigned | NO |  |  |  |
| 5 | data | text | NO |  |  |  |
| 6 | read_at | timestamp | YES |  |  |  |
| 7 | created_at | timestamp | YES |  |  |  |
| 8 | updated_at | timestamp | YES |  |  |  |
| 9 | deleted_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for nova_notifications)_

#### Table: `nova_pending_field_attachments`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | int unsigned | NO |  | PRI | auto_increment |
| 2 | draft_id | varchar(255) | NO |  | MUL |  |
| 3 | attachment | varchar(255) | NO |  |  |  |
| 4 | disk | varchar(255) | NO |  |  |  |
| 5 | created_at | timestamp | YES |  |  |  |
| 6 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for nova_pending_field_attachments)_

#### Table: `password_reset_tokens`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | email | varchar(255) | NO |  | PRI |  |
| 2 | token | varchar(255) | NO |  |  |  |
| 3 | created_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for password_reset_tokens)_

#### Table: `permissions`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | name | varchar(255) | NO |  | MUL |  |
| 3 | guard_name | varchar(255) | NO |  |  |  |
| 4 | created_at | timestamp | YES |  |  |  |
| 5 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for permissions)_

#### Table: `personal_access_tokens`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | tokenable_type | varchar(255) | NO |  | MUL |  |
| 3 | tokenable_id | bigint unsigned | NO |  |  |  |
| 4 | name | text | NO |  |  |  |
| 5 | token | varchar(64) | NO |  | UNI |  |
| 6 | abilities | text | YES |  |  |  |
| 7 | last_used_at | timestamp | YES |  |  |  |
| 8 | expires_at | timestamp | YES |  | MUL |  |
| 9 | created_at | timestamp | YES |  |  |  |
| 10 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for personal_access_tokens)_

#### Table: `question_options`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | char(36) | NO |  | PRI |  |
| 2 | question_id | char(36) | NO |  | MUL |  |
| 3 | text | varchar(255) | NO |  |  |  |
| 4 | is_correct | tinyint(1) | NO | 0 | MUL |  |
| 5 | created_at | timestamp | YES |  |  |  |
| 6 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| question_id | questions | id | question_options_question_id_foreign |

#### Table: `questions`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | char(36) | NO |  | PRI |  |
| 2 | exam_id | char(36) | NO |  | MUL |  |
| 3 | type | enum('single_select','multi_select','true_false','yes_no_ng','dropdown_cloze','gap_cloze','banked_cloze','matching','order_sentences','order_words','highlight_text','short_answer','numeric','listen_mcq','dictation','error_correction','writing_prompt','speaking_prompt') | NO | single_select |  |  |
| 4 | prompt | text | NO |  |  |  |
| 5 | position | int unsigned | NO | 1 |  |  |
| 6 | created_at | timestamp | YES |  |  |  |
| 7 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| exam_id | exams | id | questions_exam_id_foreign |

#### Table: `role_has_permissions`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | permission_id | bigint unsigned | NO |  | PRI |  |
| 2 | role_id | bigint unsigned | NO |  | PRI |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
| permission_id | permissions | id | role_has_permissions_permission_id_foreign |
| role_id | roles | id | role_has_permissions_role_id_foreign |

#### Table: `roles`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | name | varchar(255) | NO |  | MUL |  |
| 3 | guard_name | varchar(255) | NO |  |  |  |
| 4 | created_at | timestamp | YES |  |  |  |
| 5 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for roles)_

#### Table: `sessions`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | varchar(255) | NO |  | PRI |  |
| 2 | user_id | bigint unsigned | YES |  | MUL |  |
| 3 | ip_address | varchar(45) | YES |  |  |  |
| 4 | user_agent | text | YES |  |  |  |
| 5 | payload | longtext | NO |  |  |  |
| 6 | last_activity | int | NO |  | MUL |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for sessions)_

#### Table: `users`


### Columns

| # | Column | Type | Nullable | Default | Key | Extra |
|---|--------|------|----------|---------|-----|-------|
| 1 | id | bigint unsigned | NO |  | PRI | auto_increment |
| 2 | name | varchar(255) | NO |  |  |  |
| 3 | email | varchar(255) | NO |  | UNI |  |
| 4 | email_verified_at | timestamp | YES |  |  |  |
| 5 | password | varchar(255) | NO |  |  |  |
| 6 | remember_token | varchar(100) | YES |  |  |  |
| 7 | created_at | timestamp | YES |  |  |  |
| 8 | updated_at | timestamp | YES |  |  |  |

### Foreign Keys

| Column | → Table | → Column | Constraint |
|--------|---------|----------|------------|
_(failed to introspect foreign keys for users)_

### ER Summary (FKs)


```txt
- attempt_answers.attempt_id → attempts.id
- attempt_answers.question_id → questions.id
- attempt_answers.selected_option_id → question_options.id
- attempts.exam_id → exams.id
- evaluations.exam_category_id → exam_categories.id
- evaluations.exam_id → exams.id
- evaluations.user_id → users.id
- exam_categories.exam_id → exams.id
- exam_example_questions.exam_category_id → exam_categories.id
- exam_example_questions.exam_id → exams.id
- generation_logs.exam_id → exams.id
- generation_logs.generation_task_id → generation_tasks.id
- generation_tasks.exam_id → exams.id
- model_has_permissions.permission_id → permissions.id
- model_has_roles.role_id → roles.id
- question_options.question_id → questions.id
- questions.exam_id → exams.id
- role_has_permissions.permission_id → permissions.id
- role_has_permissions.role_id → roles.id

```
