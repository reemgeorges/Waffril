<?php

namespace App\Http\Requests\Basic;

use App\Http\Traits\GeneralTrait;
use App\Services\Basic\ModelColumnsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BasicRequest extends FormRequest
{
    use GeneralTrait;

    protected $modelColumnsService;

    public function __construct($model = null) {

        $this->modelColumnsService = ($model)? ModelColumnsService::getServiceFor(
            $model
        ) : null;

    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(ValidatorContract $validator)
    {
        throw new HttpResponseException($this->requiredField($validator->errors()->first()));
    }

    /**
     * Phase 1 (2026-10-25): shared fix for the "unique-on-update" bug.
     *
     * Request classes are shared between store AND update (e.g.
     * StoreUserRequest is used for both, per UserController::setVariables()
     * / FatherCrudController::store()+update()). Their `unique:table,column`
     * rules had no `ignore($id)`, so updating a record WITHOUT changing its
     * own unique field (e.g. re-saving a user without changing their email)
     * failed validation every time, because the row's own current value
     * collided with itself.
     *
     * BasicCrudService's convention (see activate/deactivate/delete/show)
     * is that the record id travels as a plain `id` request field (routes
     * are flat, e.g. POST /user/update with an `id` body field — not a
     * {id} route segment), so that is the same signal used here.
     *
     * Hooked via FormRequest::getValidatorInstance(), which calls
     * withValidator() on any FormRequest that defines it, BEFORE validation
     * runs — so every request extending BasicRequest (current and future,
     * generated or hand-written) gets this fix automatically without each
     * subclass needing to duplicate the logic.
     *
     * Only `unique:` string rules are rewritten (object-based Rule::unique()
     * rules declared explicitly by a subclass are left untouched). When no
     * `id` is present on the request (the create case), nothing is changed.
     */
    public function withValidator(Validator $validator): void
    {
        $id = $this->input('id');

        if ($id === null || $id === '') {
            return;
        }

        $rules = $validator->getRules();

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);

            foreach ($fieldRules as $index => $rule) {
                if (is_string($rule) && Str::startsWith($rule, 'unique:')) {
                    $fieldRules[$index] = $this->ignoreSelfOnUniqueRule($rule, $field, $id);
                }
            }

            $rules[$field] = $fieldRules;
        }

        $validator->setRules($rules);
    }

    /**
     * Turn a raw `unique:table,column,except,idColumn` rule string into a
     * Rule::unique(...)->ignore($id) instance so updating a record without
     * changing its own unique field passes, while changing it to a value
     * already used by a DIFFERENT record still correctly fails.
     *
     * Rule format reference: unique:table,column,ignore,idColumn
     * (column/idColumn default to the field name / 'id' respectively, same
     * defaults Laravel's own `unique:` rule uses).
     */
    protected function ignoreSelfOnUniqueRule(string $rule, string $field, $id)
    {
        $segments = explode(',', Str::after($rule, 'unique:'));

        $table    = $segments[0] ?? null;
        $column   = ($segments[1] ?? '') !== '' ? $segments[1] : $field;
        $idColumn = $segments[3] ?? 'id';

        if (!$table) {
            return $rule;
        }

        return Rule::unique($table, $column)->ignore($id, $idColumn);
    }
}
