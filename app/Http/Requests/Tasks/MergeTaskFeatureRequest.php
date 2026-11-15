<?php

namespace App\Http\Requests\Tasks;

use App\Models\GIT\TaskFeature;

class MergeTaskFeatureRequest extends UpdateTaskFeatureRequest
{
    /**
     * Validate the feature to merge into.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'into_id' => ['required', 'uuid', 'exists:task_features,id'],
        ];
    }

    /**
     * The feature the route's feature is merged into.
     *
     * @return TaskFeature The validated target feature.
     */
    public function target(): TaskFeature
    {
        return TaskFeature::query()->findOrFail($this->validated('into_id'));
    }
}
