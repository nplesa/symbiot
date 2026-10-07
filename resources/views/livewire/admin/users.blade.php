<div class="admin-users">
    <div class="table-responsive-md">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nume</th>
                    <th>Email</th>
                    <th>Adresa IP</th>
                    <th>Țara</th>
                    <th>Acțiuni</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr wire:key="user-{{ $user->id }}" @class(['table-warning' => ! $user->isApproved()])>
                        <td data-label="Nume">
                            {{ $user->name }}
                            @unless ($user->isApproved())
                                <span class="badge text-bg-warning">în așteptare</span>
                            @endunless
                            @if ($user->is_admin)
                                <span class="badge text-bg-primary">admin</span>
                            @endif
                        </td>
                        <td data-label="Email">{{ $user->email }}</td>
                        <td data-label="Adresa IP">{{ $user->registration_ip ?: '—' }}</td>
                        <td data-label="Țara">{{ $user->countryName() ?: '—' }}</td>
                        <td data-label="Acțiuni">
                            <div class="admin-users__actions">
                                @if ($user->isApproved())
                                    @unless ($user->is(auth()->user()))
                                        <form method="POST" action="{{ route('app.admin.users.ghost', $user) }}" class="d-contents">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">GHOST</button>
                                        </form>
                                    @endunless
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="edit({{ $user->id }})">EDITARE</button>
                                    @unless ($user->is(auth()->user()))
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-swal-confirm data-swal-method="delete" data-swal-id="{{ $user->id }}"
                                            data-swal-title="Ștergi utilizatorul?" data-swal-text="{{ $user->email }} va fi șters definitiv, împreună cu datele lui.">
                                            ȘTERGERE
                                        </button>
                                    @endunless
                                @else
                                    <button type="button" class="btn btn-sm btn-success" wire:click="accept({{ $user->id }})" wire:loading.attr="disabled">ACCEPT</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-swal-confirm data-swal-method="delete" data-swal-id="{{ $user->id }}"
                                        data-swal-title="Ștergi utilizatorul?" data-swal-text="{{ $user->email }} va fi șters definitiv.">
                                        DELETE
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
                        <h5 class="modal-title">Editare utilizator</h5>
                        <button type="button" class="btn-close" wire:click="cancelEdit" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="edit-name">Nume</label>
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
                        <button type="button" class="btn btn-secondary" wire:click="cancelEdit">Anulează</button>
                        <button type="submit" class="btn btn-primary">Salvează</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>