<form method="post" action="">
    <div class="form-group">
        <label for="user-id"><?= htmlspecialchars(t('user_id_integer'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="user-id" name="user-id" required value="<?= $currentAdjustableParams['user-id']??"" ?>">
    </div>
    <div class="form-group">
        <label for="lang"><?= htmlspecialchars(t('language'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="lang" name="lang">
            <?php
            $mhddosLang = $currentAdjustableParams['lang'] ?? 'ua';
            $mhddosLangOptions = ['ua', 'en', 'es', 'de', 'pl', 'it'];
            foreach ($mhddosLangOptions as $option) {
                $selected = ($mhddosLang === $option) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($option, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . strtoupper($option) . '</option>';
            }
            ?>
        </select>
    </div>
    <div class="form-group">
        <label for="copies"><?= htmlspecialchars(t('number_of_copies'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="copies" name="copies" required value="<?= $currentAdjustableParams['copies']??"" ?>" placeholder="auto or number">
    </div>
    <div class="form-group">
        <label for="use-my-ip"><?= htmlspecialchars(t('percentage_personal_ip'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="use-my-ip" name="use-my-ip" min="0" max="100" required value="<?= $currentAdjustableParams['use-my-ip']??"" ?>">
    </div>
    <div class="form-group">
        <label for="threads"><?= htmlspecialchars(t('threads'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="threads" name="threads" required value="<?= $currentAdjustableParams['threads']??"" ?>">
    </div>
    <div class="form-group">
        <label for="proxies"><?= htmlspecialchars(t('proxies_path_or_url'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="proxies" name="proxies" value="<?= $currentAdjustableParams['proxies']??"" ?>">
    </div>
    <div class="form-group">
        <label for="ifaces"><?= htmlspecialchars(t('network_interfaces'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="ifaces" name="ifaces" value="<?= $currentAdjustableParams['ifaces']??"" ?>" placeholder="eth0 eth1">
    </div>
    <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
