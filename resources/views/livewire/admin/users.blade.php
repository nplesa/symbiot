<div class="admin-users">
    <div class="table-responsive-md">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>Email</th>
                    <th>{{ __('IP address') }}</th>
                    <th>{{ __('Country') }}</th>
                    <th>{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr wire:key="user-{{ $user->id }}" @class(['table-warning' => ! $user->isApproved()])>
                        <td data-label="{{ __('Name') }}">
                            {{ $user->name }}
                            @unless ($user->isApproved())
                                <span class="badge text-bg-warning">{{ __('pending') }}</span>
                            @endunless
                            @if ($user->is_admin)
                                <span class="badge text-bg-primary">admin</span>
                            @endif
                        </td>
                        <td data-label="Email">{{ $user->email }}</td>
                        <td data-label="{{ __('IP address') }}">{{ $user->registration_ip ?: '—' }}</td>
                        <td data-label="{{ __('Country') }}">{{ $user->countryName() ?: '—' }}</td>
                        <td data-label="{{ __('Actions') }}">
                            <div class="admin-users__actions">
                                @if ($user->isApproved())
                                    @unless ($user->is(auth()->user()))
                                        <form method="POST" action="{{ route('app.admin.users.ghost', $user) }}" class="d-contents">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">GHOST</button>
                                        </form>
                                    @endunless
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="edit({{ $user->id }})">{{ __('EDIT') }}</button>
                                    @unless ($user->is(auth()->user()))
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-swal-confirm data-swal-method="delete" data-swal-id="{{ $user->id }}"
                                            data-swal-title="{{ __('Delete the user?') }}" data-swal-text="{{ __(':email will be permanently deleted, together with their data.', ['email' => $user->email]) }}">
                                            {{ __('DELETE') }}
                                        </button>
                                    @endunless
                                @else
                                    <button type="button" class="btn btn-sm btn-success" wire:click="accept({{ $user->id }})" wire:loading.attr="disabled">{{ __('ACCEPT') }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-swal-confirm data-swal-method="delete" data-swal-id="{{ $user->id }}"
                                        data-swal-title="{{ __('Delete the user?') }}" data-swal-text="{{ __(':email will be permanently deleted.', ['email' => $user->email]) }}">
                                        {{ __('DELETE') }}
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $users->links() }}</div>

    @if ($editingId)
        <div class="modal d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="save">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Edit user') }}</h5>
                        <button type="button" class="btn-close" wire:click="cancelEdit" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="edit-name">{{ __('Name') }}</label>
                            <input id="edit-name" type="text" class="form-control @error('editName') is-invalid @enderror" wire:model="editName">
                            @error('editName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit-email">Email</label>
                            <input id="edit-email" type="email" class="form-control @error('editEmail') is-invalid @enderror" wire:model="editEmail">
                            @error('editEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cancelEdit">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
