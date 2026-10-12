<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PortalAnnouncementFeed
{
    public function __construct(
        private readonly ParentPortalContext $parents,
        private readonly StaffAccess $staffAccess,
    ) {}

    public function for(Request $request, int $limit = 30): Collection
    {
        $user = $request->user();
        [$audiences, $classroomIds] = match ($user->role) {
            'student' => [['students'], collect([$user->student?->classroom_id])->filter()->values()],
            'parent' => $this->parentScope($request),
            'teacher' => [['teachers'], $user->teacher?->classrooms()->pluck('classrooms.id') ?? collect()],
            'supervisor' => [['supervisors'], $user->supervisedClassrooms()->pluck('classrooms.id')],
            'staff' => $this->staffScope($user),
            'financial_officer' => [['staff'], collect()],
            default => abort(403),
        };

        return Announcement::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($visibility) use ($audiences, $classroomIds): void {
                $allowed = array_values(array_unique(array_merge(['all', 'classroom'], $audiences)));
                $visibility->where(fn ($global) => $global->whereIn('audience', $allowed)->whereNull('classroom_id'));
                if ($classroomIds->isNotEmpty()) {
                    $visibility->orWhere(fn ($classroom) => $classroom->whereIn('audience', $allowed)->whereIn('classroom_id', $classroomIds));
                }
            })
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    /** @return array{0: array<int, string>, 1: Collection<int, int>} */
    private function parentScope(Request $request): array
    {
        $guardian = $this->parents->guardian($request);
        $classroomIds = $this->parents->children($guardian)->pluck('classroom_id')->filter()->unique()->values();

        return [['parents', 'parent'], $classroomIds];
    }

    /** @return array{0: array<int, string>, 1: Collection<int, int>} */
    private function staffScope(User $user): array
    {
        $permissions = collect($this->staffAccess->modules($user))->flatMap(fn ($module) => $module['permissions'])->unique();
        $classroomIds = $permissions->flatMap(fn (string $permission) => $this->staffAccess->classrooms($user, $permission)->modelKeys())
            ->unique()->values();

        return [['staff'], $classroomIds];
    }
}
