<fieldset><legend>症狀分類（可多選）</legend>
@foreach(\App\Http\Controllers\ClinicalController::SYMPTOMS as $symptom)
<label><input type="checkbox" name="symptoms[]" value="{{ $symptom }}" @checked(in_array($symptom,$record['symptoms'] ?? [],true))> {{ $symptom }}</label>
@endforeach</fieldset>
@foreach(\App\Http\Controllers\ClinicalController::FIELDS as $field => $label)
@if($field !== 'symptoms')
<label>{{ $label }} <textarea name="{{ $field }}" rows="3" maxlength="8000"
    @required($field === 'diagnosis' && $requireDiagnosis)>{{ $record[$field] ?? '' }}</textarea></label>
@endif
@endforeach
