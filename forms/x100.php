<form method="post" action="">
    <div class="form-group">
        <label for="itArmyUserId"><?= htmlspecialchars(t('user_id_integer'), ENT_QUOTES, 'UTF-8') ?></label>
        <input type="number" id="itArmyUserId" name="itArmyUserId" required value="<?= $currentAdjustableParams['itArmyUserId']??"" ?>">
    </div>
    <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
