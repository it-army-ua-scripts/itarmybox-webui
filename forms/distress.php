<form method="post" action="" id="distress-settings-form">
    <?php
    $distressAutotune = getDistressAutotuneSettings();
    $distressConcurrencyMode = (($distressAutotune['enabled'] ?? false) === true) ? 'auto' : 'manual';
    $distressDesiredConcurrency = (int)($distressAutotune['desiredConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY);
    $distressConfigConcurrency = (int)($distressAutotune['configConcurrency'] ?? $distressDesiredConcurrency);
    $distressLiveAppliedConcurrency = isset($distressAutotune['liveAppliedConcurrency']) && is_numeric($distressAutotune['liveAppliedConcurrency'])
        ? (int)$distressAutotune['liveAppliedConcurrency']
        : null;
    $distressConcurrencyValue = (string)($currentAdjustableParams['concurrency'] ?? $distressConfigConcurrency);
    $distressAutotuneStatusKey = (string)($distressAutotune['statusKey'] ?? 'distress_autotune_status_active');
    $distressAutotuneStatusText = $distressAutotuneStatusKey === 'distress_autotune_status_cooldown'
        ? t($distressAutotuneStatusKey, ['seconds' => (string)($distressAutotune['cooldownRemaining'] ?? 0)])
        : t($distressAutotuneStatusKey);
    $distressUploadCapStatus = (string)($distressAutotune['uploadCapStatus'] ?? 'idle');
    $distressUploadCapStatusKey = match ($distressUploadCapStatus) {
        'running' => 'distress_upload_cap_status_running',
        'success' => 'distress_upload_cap_status_success',
        'failed' => 'distress_upload_cap_status_failed',
        'skipped' => 'distress_upload_cap_status_skipped',
        default => 'distress_upload_cap_status_idle',
    };
    $distressUploadCapStatusText = t($distressUploadCapStatusKey);
    $distressUploadCapValueText = isset($distressAutotune['uploadCapMbps']) && is_numeric($distressAutotune['uploadCapMbps'])
        ? t('distress_upload_cap_value', [
            'value' => number_format((float)$distressAutotune['uploadCapMbps'], 2, '.', ''),
        ])
        : null;
    $distressUploadCapMeasuredAtText = isset($distressAutotune['uploadCapMeasuredAt']) && is_numeric($distressAutotune['uploadCapMeasuredAt']) && (int)$distressAutotune['uploadCapMeasuredAt'] > 0
        ? t('distress_upload_cap_measured_at', [
            'value' => date('Y-m-d H:i:s', (int)$distressAutotune['uploadCapMeasuredAt']),
        ])
        : null;
    $distressUploadCapMethodRaw = isset($distressAutotune['uploadCapLastMethod']) && is_string($distressAutotune['uploadCapLastMethod'])
        ? trim($distressAutotune['uploadCapLastMethod'])
        : '';
    $distressUploadCapMethodValue = match ($distressUploadCapMethodRaw) {
        'php_curl' => 'PHP cURL',
        'curl_binary' => 'curl',
        'mixed' => 'PHP cURL + curl',
        default => $distressUploadCapMethodRaw,
    };
    $distressUploadCapMethodText = $distressUploadCapMethodValue !== ''
        ? t('distress_upload_cap_method', ['value' => $distressUploadCapMethodValue])
        : null;
    $distressUploadCapErrorText = isset($distressAutotune['uploadCapLastError']) && is_string($distressAutotune['uploadCapLastError']) && trim($distressAutotune['uploadCapLastError']) !== ''
        ? t('distress_upload_cap_error', ['value' => trim($distressAutotune['uploadCapLastError'])])
        : null;
    $distressUploadCapCooldownRemaining = isset($distressAutotune['uploadCapMeasureCooldownRemaining']) && is_numeric($distressAutotune['uploadCapMeasureCooldownRemaining'])
        ? max(0, (int)$distressAutotune['uploadCapMeasureCooldownRemaining'])
        : 0;
    $distressUploadCapBlockedByActiveModules = isset($distressAutotune['uploadCapBlockedByActiveModules']) && is_array($distressAutotune['uploadCapBlockedByActiveModules'])
        ? array_values(array_filter($distressAutotune['uploadCapBlockedByActiveModules'], 'is_string'))
        : [];
    $distressUploadCapCooldownText = $distressUploadCapCooldownRemaining > 0
        ? t('distress_upload_cap_measure_cooldown', ['seconds' => (string)$distressUploadCapCooldownRemaining])
        : null;
    $distressUploadCapBlockedText = $distressUploadCapBlockedByActiveModules !== []
        ? t('distress_upload_cap_measure_requires_idle')
        : null;
    $distressHasUploadCapMeasurement = isset($distressAutotune['uploadCapMeasuredAt']) && is_numeric($distressAutotune['uploadCapMeasuredAt']) && (int)$distressAutotune['uploadCapMeasuredAt'] > 0
        && isset($distressAutotune['uploadCapMbps']) && is_numeric($distressAutotune['uploadCapMbps']) && (float)$distressAutotune['uploadCapMbps'] > 0.0;
$distressLastLoadText = isset($distressAutotune['lastLoadAverage']) && is_numeric($distressAutotune['lastLoadAverage'])
    ? t('distress_autotune_last_load', [
        'value' => number_format((float)$distressAutotune['lastLoadAverage'], 2, '.', ''),
        'target' => number_format((float)($distressAutotune['targetLoad'] ?? 1.0), 2, '.', ''),
    ])
    : null;
$distressLastRamText = isset($distressAutotune['lastRamFreePercent']) && is_numeric($distressAutotune['lastRamFreePercent'])
    ? t('distress_autotune_last_ram_free', [
        'value' => number_format((float)$distressAutotune['lastRamFreePercent'], 1, '.', ''),
        'target' => number_format((float)($distressAutotune['minFreeRamPercent'] ?? 10.0), 1, '.', ''),
    ])
    : null;
$distressLastBpsText = isset($distressAutotune['lastBpsMbps']) && is_numeric($distressAutotune['lastBpsMbps'])
    ? t('distress_autotune_last_bps', [
        'value' => number_format((float)$distressAutotune['lastBpsMbps'], 3, '.', ''),
    ])
    : null;
$distressBestBpsText = isset($distressAutotune['bestBpsMbps']) && is_numeric($distressAutotune['bestBpsMbps'])
    ? t('distress_autotune_best_bps', [
        'value' => number_format((float)$distressAutotune['bestBpsMbps'], 3, '.', ''),
        'concurrency' => isset($distressAutotune['bestBpsConcurrency']) && is_numeric($distressAutotune['bestBpsConcurrency'])
            ? (string)((int)$distressAutotune['bestBpsConcurrency'])
            : t('status_unavailable_short'),
    ])
    : null;
$distressLastTargetCountText = isset($distressAutotune['lastTargetCount']) && is_numeric($distressAutotune['lastTargetCount'])
    ? t('distress_autotune_last_target_count', [
        'value' => (string)((int)$distressAutotune['lastTargetCount']),
    ])
    : null;
    $distressUseMyIp = (int)($currentAdjustableParams['use-my-ip'] ?? 0);
    $distressFloodControlsEnabled = $distressUseMyIp > 0;
    $distressDisableUdpFlood = (string)($currentAdjustableParams['disable-udp-flood'] ?? '0');
    $distressUdpPacketSizeEnabled = $distressFloodControlsEnabled && $distressDisableUdpFlood === '0';
    $distressPacketsPerConnEnabled = $distressFloodControlsEnabled && $distressDisableUdpFlood === '0';
    $yesLabel = htmlspecialchars(t('yes'), ENT_QUOTES, 'UTF-8');
    $noLabel = htmlspecialchars(t('no'), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="form-group">
        <label for="use-tor"><?= htmlspecialchars(t('number_tor_connections'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="use-tor" name="use-tor" min="0" max="100" value="<?= $currentAdjustableParams['use-tor']??"" ?>" placeholder="<?= htmlspecialchars(t('placeholder_percent_default_0'), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group">
        <label for="distress-concurrency-mode"><?= htmlspecialchars(t('distress_concurrency_mode'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="distress-concurrency-mode" name="distress-concurrency-mode">
            <option value="auto"<?= $distressConcurrencyMode === 'auto' ? ' selected' : '' ?>><?= htmlspecialchars(t('auto_mode'), ENT_QUOTES, 'UTF-8') ?></option>
            <option value="manual"<?= $distressConcurrencyMode === 'manual' ? ' selected' : '' ?>><?= htmlspecialchars(t('manual_mode'), ENT_QUOTES, 'UTF-8') ?></option>
        </select>
        <div class="distress-autotune-panel" id="distress-autotune-panel"<?= $distressConcurrencyMode === 'auto' ? '' : ' hidden' ?>>
            <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars(t('distress_concurrency_auto_hint'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars(t('distress_autotune_status_label'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($distressAutotuneStatusText, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-status-line"><?= htmlspecialchars(t('distress_upload_cap_status_label'), ENT_QUOTES, 'UTF-8') ?> <span id="distress-upload-cap-status-text"><?= htmlspecialchars($distressUploadCapStatusText, ENT_QUOTES, 'UTF-8') ?></span></div>
            <?php if ($distressUploadCapValueText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-value-line"><?= htmlspecialchars($distressUploadCapValueText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-value-line" hidden></div>
            <?php endif; ?>
            <?php if ($distressUploadCapMeasuredAtText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-measured-at-line"><?= htmlspecialchars($distressUploadCapMeasuredAtText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-measured-at-line" hidden></div>
            <?php endif; ?>
            <?php if ($distressUploadCapMethodText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-method-line"><?= htmlspecialchars($distressUploadCapMethodText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-method-line" hidden></div>
            <?php endif; ?>
            <?php if ($distressUploadCapErrorText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-error-line"><?= htmlspecialchars($distressUploadCapErrorText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-error-line" hidden></div>
            <?php endif; ?>
            <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars(t('distress_upload_cap_manual_only_hint'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!$distressHasUploadCapMeasurement): ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-required-line"><?= htmlspecialchars(t('distress_upload_cap_required_for_auto_hint'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="schedule-limit-hint distress-autotune-line" id="distress-upload-cap-required-line" hidden></div>
            <?php endif; ?>
            <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars(t('distress_autotune_desired_value', ['value' => (string)$distressDesiredConcurrency]), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars(t('distress_autotune_config_value', ['value' => (string)$distressConfigConcurrency]), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars(t('distress_autotune_live_value', ['value' => $distressLiveAppliedConcurrency !== null ? (string)$distressLiveAppliedConcurrency : t('status_unavailable_short')]), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($distressLastLoadText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars($distressLastLoadText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($distressLastRamText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars($distressLastRamText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($distressLastBpsText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars($distressLastBpsText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($distressBestBpsText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars($distressBestBpsText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($distressLastTargetCountText !== null): ?>
                <div class="schedule-limit-hint distress-autotune-line"><?= htmlspecialchars($distressLastTargetCountText, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="form-group">
        <label for="concurrency"><?= htmlspecialchars(t('number_task_creators'), ENT_QUOTES, 'UTF-8') ?></label>
        <input
            type="number"
            id="concurrency"
            name="concurrency"
            min="64"
            max="<?= DISTRESS_MAX_CONCURRENCY ?>"
            step="64"
            value="<?= htmlspecialchars($distressConcurrencyValue, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="<?= htmlspecialchars($distressConcurrencyMode === 'auto' ? '2048' : t('placeholder_digits_default_4096'), ENT_QUOTES, 'UTF-8') ?>"
            inputmode="numeric"
        >
    </div>
    <div class="form-group">
        <label for="use-my-ip"><?= htmlspecialchars(t('percentage_personal_ip'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="use-my-ip" name="use-my-ip" min="0" max="100"
               value="<?= $currentAdjustableParams['use-my-ip']??"" ?>" placeholder="<?= htmlspecialchars(t('placeholder_percent_default_0'), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group">
        <label for="enable-icmp-flood"><?= htmlspecialchars(t('enable_icmp_flood'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="enable-icmp-flood" name="enable-icmp-flood"<?= $distressFloodControlsEnabled ? '' : ' disabled' ?>>
            <?php $enableIcmp = (string)($currentAdjustableParams['enable-icmp-flood'] ?? '0'); ?>
            <option value="0"<?= $enableIcmp === '0' ? ' selected' : '' ?>><?= $noLabel ?></option>
            <option value="1"<?= $enableIcmp === '1' ? ' selected' : '' ?>><?= $yesLabel ?></option>
        </select>
    </div>
    <div class="form-group">
        <label for="enable-packet-flood"><?= htmlspecialchars(t('enable_packet_flood'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="enable-packet-flood" name="enable-packet-flood"<?= $distressFloodControlsEnabled ? '' : ' disabled' ?>>
            <?php $enablePacket = (string)($currentAdjustableParams['enable-packet-flood'] ?? '0'); ?>
            <option value="0"<?= $enablePacket === '0' ? ' selected' : '' ?>><?= $noLabel ?></option>
            <option value="1"<?= $enablePacket === '1' ? ' selected' : '' ?>><?= $yesLabel ?></option>
        </select>
    </div>
    <div class="form-group">
        <label for="disable-udp-flood"><?= htmlspecialchars(t('disable_udp_flood'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="disable-udp-flood" name="disable-udp-flood"<?= $distressFloodControlsEnabled ? '' : ' disabled' ?>>
            <?php $disableUdp = (string)($currentAdjustableParams['disable-udp-flood'] ?? '0'); ?>
            <option value="0"<?= $disableUdp === '0' ? ' selected' : '' ?>><?= $noLabel ?></option>
            <option value="1"<?= $disableUdp === '1' ? ' selected' : '' ?>><?= $yesLabel ?></option>
        </select>
    </div>
    <div class="form-group">
        <label for="udp-packet-size"><?= htmlspecialchars(t('udp_packet_size'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="udp-packet-size" name="udp-packet-size" min="576" max="1420" value="<?= $currentAdjustableParams['udp-packet-size']??"" ?>"<?= $distressUdpPacketSizeEnabled ? '' : ' disabled' ?>>
    </div>
    <div class="form-group">
        <label for="direct-udp-mixed-flood-packets-per-conn"><?= htmlspecialchars(t('packets_per_connection'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="direct-udp-mixed-flood-packets-per-conn" name="direct-udp-mixed-flood-packets-per-conn" min="1" max="100" value="<?= $currentAdjustableParams['direct-udp-mixed-flood-packets-per-conn']??"" ?>"<?= $distressPacketsPerConnEnabled ? '' : ' disabled' ?>>
    </div>
    <div class="form-group">
        <label for="proxies-path"><?= htmlspecialchars(t('proxies_file_path'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="proxies-path" name="proxies-path" value="<?= $currentAdjustableParams['proxies-path']??"" ?>">
    </div>
    <div class="distress-form-actions">
        <button class="submit-btn distress-form-action" type="submit" name="distress-action" value="measure-upload-cap" id="distress-measure-button"<?= ($distressUploadCapCooldownRemaining > 0 || $distressUploadCapBlockedText !== null) ? ' disabled' : '' ?>><?= htmlspecialchars(t('distress_upload_cap_measure_button'), ENT_QUOTES, 'UTF-8') ?></button>
        <button class="submit-btn distress-form-action" type="submit" name="distress-action" value="save-settings"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
    </div>
    <?php if ($distressUploadCapBlockedText !== null): ?>
        <div class="schedule-limit-hint distress-autotune-line" id="distress-measure-blocked-hint"><?= htmlspecialchars($distressUploadCapBlockedText, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="schedule-limit-hint distress-autotune-line" id="distress-measure-blocked-hint" hidden></div>
    <?php endif; ?>
    <?php if ($distressUploadCapCooldownText !== null): ?>
        <div class="schedule-limit-hint distress-autotune-line" id="distress-measure-cooldown-hint"><?= htmlspecialchars($distressUploadCapCooldownText, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="schedule-limit-hint distress-autotune-line" id="distress-measure-cooldown-hint" hidden></div>
    <?php endif; ?>
</form>
<div class="status-log-modal distress-measure-modal" id="distress-measure-modal" hidden>
    <div class="status-log-modal-card distress-measure-modal-card" role="dialog" aria-modal="true" aria-labelledby="distress-measure-title">
        <div class="status-log-modal-head">
            <button
                type="button"
                class="status-log-modal-close"
                id="distress-measure-close"
                aria-label="<?= htmlspecialchars(t('close'), ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars(t('close'), ENT_QUOTES, 'UTF-8') ?>"
            >&times;</button>
        </div>
        <div class="distress-measure-modal-body">
            <h2 id="distress-measure-title"><?= htmlspecialchars(t('distress_upload_cap_progress_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="distress-measure-modal-text" id="distress-measure-status"><?= htmlspecialchars(t('distress_upload_cap_progress_preparing'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="distress-measure-progress-shell" aria-hidden="true">
                <div class="distress-measure-progress-bar" id="distress-measure-progress-bar"></div>
            </div>
            <div class="distress-measure-progress-percent" id="distress-measure-progress-percent">0%</div>
            <p class="distress-measure-modal-text distress-measure-modal-detail" id="distress-measure-detail"><?= htmlspecialchars(t('distress_upload_cap_progress_detail'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="form-message distress-measure-result" id="distress-measure-result" hidden></div>
        </div>
    </div>
</div>
<script id="distress-measure-config" type="application/json"><?= json_encode([
    'ajaxUrl' => build_tool_url('distress', ['ajax' => '1']),
    'measureStatusUrl' => build_tool_url('distress', ['ajax' => '1', 'measureStatus' => '1']),
    'hasMeasurement' => $distressHasUploadCapMeasurement,
    'cooldownRemaining' => $distressUploadCapCooldownRemaining,
    'blockedByActiveModules' => $distressUploadCapBlockedByActiveModules,
    'text' => [
        'progressTitle' => t('distress_upload_cap_progress_title'),
        'progressPreparing' => t('distress_upload_cap_progress_preparing'),
        'progressRunning' => t('distress_upload_cap_progress_running'),
        'progressAlmostDone' => t('distress_upload_cap_progress_almost_done'),
        'progressDetail' => t('distress_upload_cap_progress_detail'),
        'progressAttempt' => t('distress_upload_cap_progress_attempt', ['current' => '{{current}}', 'total' => '{{total}}']),
        'progressCloseBlocked' => t('distress_upload_cap_progress_close_blocked'),
        'progressError' => t('distress_upload_cap_progress_error'),
        'cooldownText' => t('distress_upload_cap_measure_cooldown', ['seconds' => '{{seconds}}']),
        'blockedText' => t('distress_upload_cap_measure_requires_idle'),
        'statusIdle' => t('distress_upload_cap_status_idle'),
        'statusRunning' => t('distress_upload_cap_status_running'),
        'statusSuccess' => t('distress_upload_cap_status_success'),
        'statusFailed' => t('distress_upload_cap_status_failed'),
        'statusSkipped' => t('distress_upload_cap_status_skipped'),
        'statusLabel' => t('distress_upload_cap_status_label'),
        'valueText' => t('distress_upload_cap_value', ['value' => '{{value}}']),
        'measuredAtText' => t('distress_upload_cap_measured_at', ['value' => '{{value}}']),
        'methodText' => t('distress_upload_cap_method', ['value' => '{{value}}']),
        'errorText' => t('distress_upload_cap_error', ['value' => '{{value}}']),
        'startAutoReady' => t('distress_start_auto_ready'),
        'startAutoBlocked' => t('distress_start_auto_blocked'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/js/distress_measure.js"></script>
<script>
    (function () {
        const useMyIpEl = document.getElementById("use-my-ip");
        const concurrencyModeEl = document.getElementById("distress-concurrency-mode");
        const concurrencyEl = document.getElementById("concurrency");
        const autotunePanelEl = document.getElementById("distress-autotune-panel");
        const gatedFields = [
            document.getElementById("enable-icmp-flood"),
            document.getElementById("enable-packet-flood"),
            document.getElementById("disable-udp-flood")
        ];
        const disableUdpFloodEl = document.getElementById("disable-udp-flood");
        const udpPacketSizeEl = document.getElementById("udp-packet-size");
        const packetsPerConnEl = document.getElementById("direct-udp-mixed-flood-packets-per-conn");

        function refreshFloodControlsState() {
            const useMyIpValue = parseInt(useMyIpEl.value || "0", 10);
            const enabled = !Number.isNaN(useMyIpValue) && useMyIpValue > 0;
            gatedFields.forEach((el) => {
                if (!el) {
                    return;
                }
                el.disabled = !enabled;
            });
            if (udpPacketSizeEl) {
                const udpFloodIsDisabled = disableUdpFloodEl && disableUdpFloodEl.value === "1";
                udpPacketSizeEl.disabled = !enabled || udpFloodIsDisabled;
            }
            if (packetsPerConnEl) {
                const udpFloodIsDisabled = disableUdpFloodEl && disableUdpFloodEl.value === "1";
                packetsPerConnEl.disabled = !enabled || udpFloodIsDisabled;
            }
        }

        function refreshConcurrencyModeState() {
            if (!concurrencyModeEl || !concurrencyEl) {
                return;
            }
            const autoMode = concurrencyModeEl.value === "auto";
            concurrencyEl.readOnly = autoMode;
            concurrencyEl.setAttribute("aria-disabled", autoMode ? "true" : "false");
            if (autotunePanelEl) {
                autotunePanelEl.hidden = !autoMode;
            }
        }

        if (useMyIpEl) {
            useMyIpEl.addEventListener("input", refreshFloodControlsState);
            useMyIpEl.addEventListener("change", refreshFloodControlsState);
            if (disableUdpFloodEl) {
                disableUdpFloodEl.addEventListener("change", refreshFloodControlsState);
            }
            refreshFloodControlsState();
        }
        if (concurrencyModeEl) {
            concurrencyModeEl.addEventListener("change", refreshConcurrencyModeState);
            refreshConcurrencyModeState();
        }
    })();
</script>
