<form method="post" action="">
    <?php
    $distressUseMyIp = (int)($currentAdjustableParams['use-my-ip'] ?? 0);
    $distressFloodControlsEnabled = $distressUseMyIp > 0;
    $yesLabel = htmlspecialchars(t('yes'), ENT_QUOTES, 'UTF-8');
    $noLabel = htmlspecialchars(t('no'), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="form-group">
        <label for="user-id"><?= htmlspecialchars(t('user_id_integer'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="user-id" name="user-id" required value="<?= $currentAdjustableParams['user-id']??"" ?>">
    </div>
    <div class="form-group">
        <label for="use-my-ip"><?= htmlspecialchars(t('percentage_personal_ip'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="use-my-ip" name="use-my-ip" min="0" max="100"
               required value="<?= $currentAdjustableParams['use-my-ip']??"" ?>">
    </div>
    <div class="form-group">
        <label for="use-tor"><?= htmlspecialchars(t('number_tor_connections'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="use-tor" name="use-tor" min="0" max="100" required value="<?= $currentAdjustableParams['use-tor']??"" ?>">
    </div>
    <div class="form-group">
        <label for="concurrency"><?= htmlspecialchars(t('number_task_creators'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="concurrency" name="concurrency" min="50" max="100000" required value="<?= $currentAdjustableParams['concurrency']??"" ?>">
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
        <input type="number" id="udp-packet-size" name="udp-packet-size" min="576" max="1420" value="<?= $currentAdjustableParams['udp-packet-size']??"" ?>">
    </div>
    <div class="form-group">
        <label for="direct-udp-mixed-flood-packets-per-conn"><?= htmlspecialchars(t('packets_per_connection'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="direct-udp-mixed-flood-packets-per-conn" name="direct-udp-mixed-flood-packets-per-conn" min="1" max="100" value="<?= $currentAdjustableParams['direct-udp-mixed-flood-packets-per-conn']??"" ?>">
    </div>
    <div class="form-group">
        <label for="proxies-path"><?= htmlspecialchars(t('proxies_file_path'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="proxies-path" name="proxies-path" value="<?= $currentAdjustableParams['proxies-path']??"" ?>">
    </div>
    <div class="form-group">
        <label for="interface"><?= htmlspecialchars(t('network_interface'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="interface" name="interface" value="<?= $currentAdjustableParams['interface']??"" ?>" placeholder="eth0,eth1">
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

        function refreshFloodControlsState() {
            const useMyIpValue = parseInt(useMyIpEl.value || "0", 10);
            const enabled = !Number.isNaN(useMyIpValue) && useMyIpValue > 0;
            gatedFields.forEach((el) => {
                if (!el) {
                    return;
                }
                el.disabled = !enabled;
            });
        }

        if (useMyIpEl) {
            useMyIpEl.addEventListener("input", refreshFloodControlsState);
            useMyIpEl.addEventListener("change", refreshFloodControlsState);
            refreshFloodControlsState();
        }
    })();
</script>
