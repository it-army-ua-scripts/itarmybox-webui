<form method="post" action="">
    <div class="form-group">
        <label for="user-id"><?= htmlspecialchars(t('user_id_integer'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="user-id" name="user-id" required value="<?= $currentAdjustableParams['user-id']??"" ?>">
    </div>
    <div class="form-group">
        <label for="copies"><?= htmlspecialchars(t('number_of_copies'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="copies" name="copies" required value="<?= $currentAdjustableParams['copies']??"" ?>">
    </div>
    <div class="form-group">
        <label for="use-my-ip"><?= htmlspecialchars(t('percentage_personal_ip'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="use-my-ip" name="use-my-ip" min="0" max="100" required value="<?= $currentAdjustableParams['use-my-ip']??"" ?>">
    </div>
    <div class="form-group">
        <label for="threads"><?= htmlspecialchars(t('threads'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="threads" name="threads" required value="<?= $currentAdjustableParams['threads']??"" ?>">
    </div>
    <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
