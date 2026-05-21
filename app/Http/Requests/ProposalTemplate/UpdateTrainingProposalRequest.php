<?php

namespace App\Http\Requests\ProposalTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrainingProposalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $agenda = $this->input('agenda');
        if (is_array($agenda)) {
            $agenda = array_map(static function ($row) {
                if (!is_array($row)) {
                    return $row;
                }
                return [
                    'day'        => $row['day'] ?? null,
                    'start_time' => $row['start_time'] ?? ($row['start'] ?? null),
                    'end_time'   => $row['end_time'] ?? ($row['end'] ?? null),
                    'topic'      => $row['topic'] ?? ($row['activity'] ?? null),
                ];
            }, $agenda);
        }

        $this->merge([
            'trainingTitle'                  => $this->input('trainingTitle', $this->input('training_title')),
            'trainingCode'                   => $this->input('trainingCode', $this->input('training_code')),
            'hrdNo'                          => $this->input('hrdNo', $this->input('hrd_no')),
            'trainingRequirements'           => $this->input('trainingRequirements', $this->input('training_requirements')),
            'additionalTrainingRequirements' => $this->input(
                'additionalTrainingRequirements',
                $this->input('additionalRequirements', $this->input('additional_requirements'))
            ),
            'trainingMaterials'              => $this->input('trainingMaterials', $this->input('training_materials')),
            'lectureMedium'                  => $this->input('lectureMedium', $this->input('lecture_medium')),
            'method_theory'                  => $this->input('method_theory', $this->input('methodTheory')),
            'method_theory_desc'             => $this->input('method_theory_desc', $this->input('methodTheoryDesc')),
            'method_practical'               => $this->input('method_practical', $this->input('methodPractical')),
            'method_practical_desc'          => $this->input('method_practical_desc', $this->input('methodPracticalDesc')),
            'remarks'                        => $this->input('remarks', $this->input('remark')),
            'agenda'                         => $agenda,
        ]);
    }

    public function rules(): array
    {
        return [
            'trainingTitle'                    => ['required', 'string', 'max:255'],
            'trainingCode'                     => ['required', 'string', 'max:50'],
            'hrdNo'                            => ['nullable', 'string', 'max:20'],
            'introduction'                     => ['required', 'string'],
            'objectives'                       => ['required', 'string'],
            'modules'                          => ['nullable', 'string'],
            'trainingRequirements'             => ['nullable', 'string'],
            'additionalTrainingRequirements'   => ['nullable', 'string'],
            'trainingMaterials'                => ['nullable', 'string'],
            'lectureMedium'                    => ['nullable', 'string', 'max:255'],
            'duration'                         => ['required', 'string', 'max:100'],
            'method_theory'                    => ['nullable', 'boolean'],
            'method_theory_desc'               => ['nullable', 'string'],
            'method_practical'                 => ['nullable', 'boolean'],
            'method_practical_desc'            => ['nullable', 'string'],
            'agenda'                           => ['nullable', 'array'],
            'agenda.*.day'                     => ['required_with:agenda', 'integer', 'min:1'],
            'agenda.*.start_time'              => ['required_with:agenda', 'string'],
            'agenda.*.end_time'                => ['required_with:agenda', 'string'],
            'agenda.*.topic'                   => ['required_with:agenda', 'string', 'max:500'],
            'remarks'                          => ['nullable', 'string', 'max:1000'],
        ];
    }
}
