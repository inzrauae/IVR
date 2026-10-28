<?php

use App\Authorization\Role;
use App\Models\Department;
use App\Models\Extension;
use App\Models\User;
use App\Tenancy\Tenant;

function departmentExtension(User $owner, string $number): Extension
{
    return app(Tenant::class)->runAs($owner->organization, fn () => Extension::factory()->create([
        'organization_id' => $owner->organization_id,
        'number' => $number,
    ]));
}

it('creates a department with member extensions and moves them on update', function () {
    $owner = User::factory()->create();
    $a = departmentExtension($owner, '100');
    $b = departmentExtension($owner, '101');

    $this->actingAs($owner)
        ->post(route('departments.store'), ['name' => 'Sales', 'extension_ids' => [$a->id, $b->id]])
        ->assertSessionHas('success');

    $sales = Department::withoutGlobalScopes()->where('name', 'Sales')->sole();
    expect($a->fresh()->department_id)->toBe($sales->id)->and($b->fresh()->department_id)->toBe($sales->id);

    $this->actingAs($owner)->post(route('departments.store'), ['name' => 'Support', 'extension_ids' => [$b->id]]);
    $this->actingAs($owner)->put(route('departments.update', $sales->id), ['name' => 'Sales', 'extension_ids' => []]);

    expect($a->fresh()->department_id)->toBeNull()
        ->and($b->fresh()->department->name)->toBe('Support');
});

it('unassigns extensions when their department is deleted', function () {
    $owner = User::factory()->create();
    $ext = departmentExtension($owner, '100');
    $this->actingAs($owner)->post(route('departments.store'), ['name' => 'Ops', 'extension_ids' => [$ext->id]]);
    $ops = Department::withoutGlobalScopes()->where('name', 'Ops')->sole();

    $this->actingAs($owner)->delete(route('departments.destroy', $ops->id))->assertSessionHas('success');

    expect($ext->fresh()->department_id)->toBeNull()
        ->and(Extension::withoutGlobalScopes()->find($ext->id))->not->toBeNull();
});

it('rejects another organization\'s extensions and duplicate names', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $foreign = departmentExtension($other, '100');

    $this->actingAs($owner)
        ->post(route('departments.store'), ['name' => 'Sales', 'extension_ids' => [$foreign->id]])
        ->assertSessionHasErrors('extension_ids.0');

    $this->actingAs($owner)->post(route('departments.store'), ['name' => 'Sales']);
    $this->actingAs($owner)->post(route('departments.store'), ['name' => 'Sales'])->assertSessionHasErrors('name');

    // The same name in another organization is fine.
    $this->actingAs($other)->post(route('departments.store'), ['name' => 'Sales'])->assertSessionHasNoErrors();
});

it('lets supervisors in only where permitted', function () {
    $owner = User::factory()->create();
    $manager = User::factory()->for($owner->organization)->role(Role::CallCenterManager)->create();
    $agent = User::factory()->for($owner->organization)->role(Role::Agent)->create();

    $this->actingAs($manager)->get(route('departments.index'))->assertOk();
    $this->actingAs($manager)->post(route('departments.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($agent)->get(route('departments.index'))->assertForbidden();
});
