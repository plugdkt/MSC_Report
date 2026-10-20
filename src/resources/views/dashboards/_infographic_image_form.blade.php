<form method="POST" action="{{ route('dashboards.image.upload', $dashboard) }}" enctype="multipart/form-data" class="form-row" style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
    @csrf
    <label>รูปหัวรายงาน (อาคาร/โลโก้ · jpg/png ≤ 5 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
    <button class="btn btn-outline" type="submit">อัปโหลดรูป</button>
</form>
