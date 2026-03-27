<form method="post" action="">
    <?php
    $distressAutotune = getDistressAutotuneSettings();
    $distressConcurrencyMode = (($distressAutotune['enabled'] ?? true) === true) ? 'auto' : 'manual';
    $distressConcurrencyValue = (string)($currentAdjustableParams['concurrency'] ?? ($distressAutotune['currentConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY));
    $distressAutotuneStatusKey = (string)($distressAutotune['statusKey'] ?? 'distress_autotune_status_active');
    $distressAutotuneStatusText = $distressAutotuneStatusKey === 'distress_autotune_status_cooldown'
        ? t($distressAutotuneStatusKey, ['seconds' => (string)($distressAutotune['cooldownRemaining'] ?? 0)])
        : t($distressAutotuneStatusKey);
$distressLastLoadText = isset($distressAutotune['lastLoadAverage']) && is_numeric($distressAutotune['lastLoadAverage'])
    ? t('distress_autotune_last_load', [
        'value' => number_format((float)$distressAutotune['lastLoadAverage'], 2, '.', ''),
        'target' => number_format((float)($distressAutotune['targetLoad'] ?? 4.2), 1, '.', ''),
    ])
    : null;
$distressLastRamText = isset($distressAutotune['lastRamFreePercent']) && is_numeric($distressAutotune['lastRamFreePercent'])
    ? t('distress_autotune_last_ram_free', [
        'value' => number_format((float)$distressAutotune['lastRamFreePercent'], 1, '.', ''),
        'target' => number_format((float)($distressAutotune['minFreeRamPercent'] ?? 10.0), 1, '.', ''),
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
        <div class="schedule-limit-hint"><?= htmlspecialchars(t('distress_concurrency_auto_hint'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="schedule-limit-hint"><?= htmlspecialchars(t('distress_autotune_status_label'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($distressAutotuneStatusText, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="schedule-limit-hint"><?= htmlspecialchars(t('distress_autotune_current_value', ['value' => $distressConcurrencyValue]), ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($distressLastLoadText !== null): ?>
            <div class="schedule-limit-hint"><?= htmlspecialchars($distressLastLoadText, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($distressLastRamText !== null): ?>
            <div class="schedule-limit-hint"><?= htmlspecialchars($distressLastRamText, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>
    <div class="form-group">
        <label for="concurrency"><?= htmlspecialchars(t('number_task_creators'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="concurrency" name="concurrency" value="<?= htmlspecialchars($distressConcurrencyValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($distressConcurrencyMode === 'auto' ? '1024' : t('placeholder_digits_default_4096'), ENT_QUOTES, 'UTF-8') ?>" pattern="\d+" inputmode="numeric">
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
    <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<script>
    (function () {
        const useMyIpEl = document.getElementById("use-my-ip");
        const concurrencyModeEl = document.getElementById("distress-concurrency-mode");
        const concurrencyEl = document.getElementById("concurrency");
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
            concurrencyEl.disabled = concurrencyModeEl.value === "auto";
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
