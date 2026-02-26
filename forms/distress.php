<form method="post" action="">
    <?php
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
        <input type="number" id="use-tor" name="use-tor" min="0" max="100" value="<?= $currentAdjustableParams['use-tor']??"" ?>" placeholder="0..100 (default: 0)">
    </div>
    <div class="form-group">
        <label for="concurrency"><?= htmlspecialchars(t('number_task_creators'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="concurrency" name="concurrency" value="<?= $currentAdjustableParams['concurrency']??"" ?>" placeholder="digits only (default: 4096)">
    </div>
    <div class="form-group">
        <label for="use-my-ip"><?= htmlspecialchars(t('percentage_personal_ip'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="use-my-ip" name="use-my-ip" min="0" max="100"
               value="<?= $currentAdjustableParams['use-my-ip']??"" ?>" placeholder="0..100 (default: 0)">
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

        if (useMyIpEl) {
            useMyIpEl.addEventListener("input", refreshFloodControlsState);
            useMyIpEl.addEventListener("change", refreshFloodControlsState);
            if (disableUdpFloodEl) {
                disableUdpFloodEl.addEventListener("change", refreshFloodControlsState);
            }
            refreshFloodControlsState();
        }
    })();
</script>
