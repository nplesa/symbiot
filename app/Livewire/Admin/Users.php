<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Users extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public string $editName = '';

    public string $editEmail = '';

    public function render(): View
    {
        $this->authorizeAdmin();

        $users = User::query()
            ->orderByRaw('approved_at IS NOT NULL')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.admin.users', ['users' => $users]);
    }

    public function accept(int $userId): void
    {
        $this->authorizeAdmin();

        $user = User::query()->findOrFail($userId);

        if (! $user->isApproved()) {
            $user->approved_at = now();
            $user->save();
        }
    }

    public function delete(int $userId): void
    {
        $this->authorizeAdmin();

        $user = User::query()->findOrFail($userId);

        abort_if($user->is(auth()->user()), 403);

        $user->tokens()->delete();
        $user->delete();

        if ($this->editingId === $userId) {
            $this->cancelEdit();
        }
    }

    public function edit(int $userId): void
    {
        $this->authorizeAdmin();

        $user = User::query()->findOrFail($userId);

        $this->editingId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        abort_if($this->editingId === null, 404);

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editEmail' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
        ]);

        User::query()->findOrFail($this->editingId)->update([
            'name' => $data['editName'],
            'email' => $data['editEmail'],
        ]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editEmail');
        $this->resetValidation();
    }

    private function authorizeAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && $user->is_admin && $user->isApproved(), 403);
    }
}
