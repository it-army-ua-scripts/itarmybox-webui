<form method="post" action="">
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
    <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
