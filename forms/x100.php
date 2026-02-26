<form method="post" action="">
    <?php
    $yesLabel = htmlspecialchars(t('yes'), ENT_QUOTES, 'UTF-8');
    $noLabel = htmlspecialchars(t('no'), ENT_QUOTES, 'UTF-8');
    $ignoreBundledFreeVpn = (string)($currentAdjustableParams['ignoreBundledFreeVpn'] ?? '0');
    ?>
    <div class="form-group">
        <label for="initialDistressScale"><?= htmlspecialchars(t('initial_distress_scale'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="initialDistressScale" name="initialDistressScale" min="10" max="40960" value="<?= $currentAdjustableParams['initialDistressScale']??"" ?>">
    </div>
    <div class="form-group">
        <label for="ignoreBundledFreeVpn"><?= htmlspecialchars(t('ignore_bundled_free_vpn'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="ignoreBundledFreeVpn" name="ignoreBundledFreeVpn">
            <option value="0"<?= $ignoreBundledFreeVpn === '0' ? ' selected' : '' ?>><?= $noLabel ?></option>
            <option value="1"<?= $ignoreBundledFreeVpn === '1' ? ' selected' : '' ?>><?= $yesLabel ?></option>
        </select>
    </div>
    <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
