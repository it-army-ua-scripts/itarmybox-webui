<form method="post" action="">
    <div class="form-group">
        <label for="user-id">User ID (Integer):</label>
        <input type="number" id="user-id" name="user-id" required value="<?= $currentAdjustableParams['user-id']??"" ?>">
    </div>
    <div class="form-group">
        <label for="use-my-ip">Percentage of personal IP:</label>
        <input type="number" id="use-my-ip" name="use-my-ip" min="0" max="100"
               required value="<?= $currentAdjustableParams['use-my-ip']??"" ?>">
    </div>
    <div class="form-group">
        <label for="use-tor">Number of Tor connections:</label>
        <input type="number" id="use-tor" name="use-tor" min="0" max="100" required value="<?= $currentAdjustableParams['use-tor']??"" ?>">
    </div>
    <div class="form-group">
        <label for="concurrency">Number of task creators:</label>
        <input type="number" id="concurrency" name="concurrency" min="50" max="100000" required value="<?= $currentAdjustableParams['concurrency']??"" ?>">
    </div>
    <button class="submit-btn" type="submit">Save</button>
</form>
