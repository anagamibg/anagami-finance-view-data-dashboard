{{-- Result of the last "Fetch from ECB": $result = [date, count, skipped, fetched_at] (flashed by FxRateController::fetchEcb) --}}
<div class="alert alert-success f-13 mb-0" role="status">
    <i class="fa fa-check-circle mr-1"></i>
    <span class="f-w-500">{{ __('databackd-gaps-fx-rates.ecb.result', ['date' => $result['date'], 'count' => $result['count']]) }}</span>
    @if (!empty($result['skipped']))
        <span class="d-block mt-1">{{ __('databackd-gaps-fx-rates.ecb.result_skipped', ['count' => $result['skipped']]) }}</span>
    @endif
    <span class="d-block mt-1 text-dark-grey">{{ __('databackd-gaps-fx-rates.ecb.result_time', ['time' => $result['fetched_at']]) }}</span>
</div>
