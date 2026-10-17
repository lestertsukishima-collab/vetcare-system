<x-clinic.field name="name" label="Pet name" :value="$pet->name ?? ''" required maxlength="100" />
<x-clinic.field name="species" label="Species" :value="$pet->species ?? ''" placeholder="Dog, Cat, Rabbit…" required maxlength="100" />
<x-clinic.field name="breed" label="Breed" :value="$pet->breed ?? ''" maxlength="100" />
<x-clinic.field name="birth_date" label="Birth date" type="date" :value="isset($pet) ? $pet->birth_date?->format('Y-m-d') : ''" :max="now()->toDateString()" />
<x-clinic.select name="sex" label="Sex" :options="['Male'=>'Male','Female'=>'Female','Unknown'=>'Unknown']" :value="$pet->sex ?? 'Unknown'" required />
<x-clinic.field name="weight" label="Weight (kg)" type="number" min="0.01" max="999999.99" step="0.01" :value="$pet->weight ?? ''" />
<x-clinic.field name="color" label="Color" :value="$pet->color ?? ''" maxlength="100" />
<x-clinic.field name="notes" label="Notes & allergies" type="textarea" :value="$pet->notes ?? ''" maxlength="5000" />
