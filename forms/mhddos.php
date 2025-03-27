<form method="post" action="">
    <div class="form-group">
        <label for="user-id">User ID (Integer):</label>
        <input type="number" id="user-id" name="user-id" required value="<?= $currentAdjustableParams['user-id']??"" ?>">
    </div>
    <div class="form-group">
        <label for="copies">Number of copies:</label>
        <input type="number" id="copies" name="copies" required value="<?= $currentAdjustableParams['copies']??"" ?>">
    </div>
    <div class="form-group">
        <label for="use-my-ip">Percentage of personal IP:</label>
        <input type="number" id="use-my-ip" name="use-my-ip" min="0" max="100" required value="<?= $currentAdjustableParams['use-my-ip']??"" ?>">
    </div>
    <div class="form-group">
        <label for="threads">Threads:</label>
        <input type="number" id="threads" name="threads" required value="<?= $currentAdjustableParams['threads']??"" ?>">
    </div>
    <button class="submit-btn" type="submit">Save</button>
</form>
