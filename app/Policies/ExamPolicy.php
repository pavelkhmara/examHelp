<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Разрешаем просмотр списка экзаменов всем авторизованным пользователям
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Exam $exam): bool
    {
        // Разрешаем просмотр экзамена всем авторизованным пользователям
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Разрешаем создание экзаменов всем авторизованным пользователям
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Exam $exam): bool
    {
        // Разрешаем редактирование экзаменов всем авторизованным пользователям
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Exam $exam): bool
    {
        // Разрешаем удаление экзаменов всем авторизованным пользователям
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Exam $exam): bool
    {
        // Разрешаем восстановление экзаменов всем авторизованным пользователям
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Exam $exam): bool
    {
        // Разрешаем окончательное удаление экзаменов всем авторизованным пользователям
        return true;
    }
}
