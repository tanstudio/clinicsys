<fieldset id="record-symptoms" tabindex="-1" @if(isset($recordErrors['symptoms'])) aria-describedby="error-symptoms" @endif><legend>症狀分類（可多選）</legend>
@foreach(\App\Http\Controllers\ClinicalController::SYMPTOMS as $symptom)
<label><input type="checkbox" name="symptoms[]" value="{{ $symptom }}" @checked(in_array($symptom,$record['symptoms'] ?? [],true))> {{ $symptom }}</label>
@endforeach
@if(isset($recordErrors['symptoms']))<p id="error-symptoms">{{ $recordErrors['symptoms'] }}</p>@endif
</fieldset>
@foreach(\App\Http\Controllers\ClinicalController::FIELDS as $field => $label)
@if($field !== 'symptoms')
<label>{{ $label }} <textarea id="record-{{ $field }}" name="{{ $field }}" rows="3" maxlength="8000"
    @if(isset($recordErrors[$field])) aria-invalid="true" aria-describedby="error-{{ $field }}" @endif
    @required($field === 'diagnosis' && $requireDiagnosis)>{{ $record[$field] ?? '' }}</textarea>
@if(isset($recordErrors[$field]))<span id="error-{{ $field }}">{{ $recordErrors[$field] }}</span>@endif
</label>
@endif
@endforeach
