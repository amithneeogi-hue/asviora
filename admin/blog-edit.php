<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();

$id   = (int) get('id');
$post = $id ? fetch_one('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
if ($id && !$post) {
    flash('That post no longer exists.', 'error');
    redirect('admin/blog.php');
}

$admin_title = $post ? 'Edit Post' : 'Write a New Post';
$admin_sub   = $post ? 'Last updated ' . fdate($post['updated_at'], 'd M Y, H:i') : 'Draft it, preview it, publish it';
$admin_actions = '<a href="' . admin_url('blog.php') . '" class="btn btn-ghost btn-sm">&larr; All posts</a>';

$cats   = fetch_all('SELECT id, name FROM blog_categories ORDER BY name');
$errors = [];

/* default form values */
$f = [
    'title'       => $post['title']       ?? '',
    'slug'        => $post['slug']        ?? '',
    'excerpt'     => $post['excerpt']     ?? '',
    'content'     => $post['content']     ?? '',
    'category_id' => $post['category_id'] ?? '',
    'tags'        => $post['tags']        ?? '',
    'meta_title'  => $post['meta_title']  ?? '',
    'meta_desc'   => $post['meta_desc']   ?? '',
    'status'      => $post['status']      ?? 'draft',
    'is_featured' => $post['is_featured'] ?? 0,
];

/* ---------------- save ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Session expired. Please submit again.';
    } else {
        $f['title']       = post('title');
        $f['slug']        = post('slug');
        $f['excerpt']     = mb_substr(post('excerpt'), 0, 400);
        $f['content']     = safe_html((string) ($_POST['content'] ?? ''));
        $f['category_id'] = post('category_id');
        $f['tags']        = mb_substr(post('tags'), 0, 255);
        $f['meta_title']  = mb_substr(post('meta_title'), 0, 200);
        $f['meta_desc']   = mb_substr(post('meta_desc'), 0, 300);
        $f['status']      = post('status') === 'published' ? 'published' : 'draft';
        $f['is_featured'] = !empty($_POST['is_featured']) ? 1 : 0;

        if (mb_strlen($f['title']) < 3) {
            $errors[] = 'The title needs at least 3 characters.';
        }
        if (trim(strip_tags($f['content'])) === '') {
            $errors[] = 'The article body cannot be empty.';
        }

        if (!$errors) {
            $slug = $f['slug'] !== '' ? slugify($f['slug']) : slugify($f['title']);
            $slug = unique_slug('blog_posts', $slug, $id ?: null);

            $data = [
                'title'       => $f['title'],
                'slug'        => $slug,
                'excerpt'     => $f['excerpt'] !== '' ? $f['excerpt'] : excerpt($f['content'], 200),
                'content'     => $f['content'],
                'category_id' => $f['category_id'] !== '' ? (int) $f['category_id'] : null,
                'tags'        => $f['tags'] !== '' ? $f['tags'] : null,
                'meta_title'  => $f['meta_title'] !== '' ? $f['meta_title'] : null,
                'meta_desc'   => $f['meta_desc'] !== '' ? $f['meta_desc'] : null,
                'status'      => $f['status'],
                'is_featured' => $f['is_featured'],
            ];

            $cover = save_upload('cover', 'blog');
            if ($cover) {
                if ($post) delete_upload($post['cover_image'], 'blog');
                $data['cover_image'] = $cover;
            } elseif ($post && post('remove_cover') === '1') {
                delete_upload($post['cover_image'], 'blog');
                $data['cover_image'] = null;
            }

            if ($f['status'] === 'published') {
                if (!$post || $post['published_at'] === null) {
                    $data['published_at'] = date('Y-m-d H:i:s');
                }
            }

            if ($post) {
                update_row('blog_posts', $data, 'id = ?', [$id]);
                flash('Post saved' . ($f['status'] === 'published' ? ' and published.' : ' as a draft.'));
            } else {
                $data['author_id'] = (int) $A['id'];
                $id = insert_row('blog_posts', $data);
                flash('Post created' . ($f['status'] === 'published' ? ' and published.' : ' as a draft.'));
            }
            redirect('admin/blog-edit.php?id=' . $id);
        }
    }
}

$coverUrl = $post ? cover_url($post['cover_image']) : null;

require __DIR__ . '/includes/layout.php';
?>

<?php foreach ($errors as $er): ?>
  <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($er) ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" id="postForm">
  <?= csrf_field() ?>

  <div class="adm-form-grid">

    <!-- ============ main column ============ -->
    <div>
      <div class="adm-panel">
        <div class="adm-panel-body">
          <div class="form-group">
            <label class="form-label" for="title">Title <span class="req">*</span></label>
            <input class="form-control" id="title" name="title" value="<?= e($f['title']) ?>"
                   placeholder="How to solve a Rubik's cube in under a minute" required
                   style="font-size:1.1rem;font-weight:700">
          </div>

          <div class="form-group">
            <label class="form-label" for="slug">URL slug</label>
            <input class="form-control" id="slug" name="slug" value="<?= e($f['slug']) ?>"
                   placeholder="auto-generated from the title">
            <div class="form-hint">
              Preview: <span class="text-green" id="slugPreview"><?= url('post.php?slug=') ?><span id="slugOut"><?= e($f['slug']) ?></span></span>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="excerpt">Short excerpt</label>
            <textarea class="form-control" id="excerpt" name="excerpt" maxlength="400"
                      style="min-height:80px"
                      placeholder="One or two sentences shown on the blog listing and in search results."><?= e($f['excerpt']) ?></textarea>
            <div class="form-hint"><span id="excCount"><?= mb_strlen($f['excerpt']) ?></span>/400 characters
              &middot; leave blank to auto-generate</div>
          </div>
        </div>
      </div>

      <div class="adm-panel">
        <div class="adm-panel-head">
          <div><h2>Article body <span class="req">*</span></h2>
            <p>Select text and use the toolbar, or paste formatted content straight in</p></div>
          <button type="button" class="btn btn-ghost btn-xs" id="btnHtml">Toggle HTML</button>
        </div>
        <div class="adm-panel-body">

          <div class="adm-editor-toolbar" id="etb">
            <button type="button" data-cmd="formatBlock" data-val="h2" title="Heading 2">H2</button>
            <button type="button" data-cmd="formatBlock" data-val="h3" title="Heading 3">H3</button>
            <button type="button" data-cmd="formatBlock" data-val="p" title="Paragraph">P</button>
            <span class="sep"></span>
            <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
            <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
            <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
            <button type="button" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
            <span class="sep"></span>
            <button type="button" data-cmd="insertUnorderedList" title="Bullet list">&#8226; List</button>
            <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
            <button type="button" data-cmd="formatBlock" data-val="blockquote" title="Quote">&ldquo;&rdquo;</button>
            <button type="button" data-cmd="formatBlock" data-val="pre" title="Code block">&lt;/&gt;</button>
            <span class="sep"></span>
            <button type="button" id="btnLink" title="Insert link">&#128279;</button>
            <button type="button" id="btnUnlink" title="Remove link">&#9986;</button>
            <button type="button" id="btnImg" title="Insert image by URL">&#128247;</button>
            <span class="sep"></span>
            <button type="button" data-cmd="insertHorizontalRule" title="Divider">&mdash;</button>
            <button type="button" data-cmd="removeFormat" title="Clear formatting">&#10006;</button>
          </div>

          <div id="editor" contenteditable="true"
               data-placeholder="Start writing your article here…"><?= safe_html((string) $f['content']) ?></div>

          <textarea name="content" id="contentField" style="display:none"></textarea>

          <div class="row between mt-2">
            <span class="text-dim"><span id="wordCount">0</span> words &middot;
              <span id="readTime">0</span> min read</span>
            <span class="text-dim">Autosaved locally as you type</span>
          </div>
        </div>
      </div>

      <div class="adm-panel">
        <div class="adm-panel-head"><h2>SEO</h2></div>
        <div class="adm-panel-body">
          <div class="form-group">
            <label class="form-label" for="meta_title">Meta title</label>
            <input class="form-control" id="meta_title" name="meta_title"
                   value="<?= e($f['meta_title']) ?>" maxlength="200"
                   placeholder="Defaults to the post title">
          </div>
          <div class="form-group">
            <label class="form-label" for="meta_desc">Meta description</label>
            <textarea class="form-control" id="meta_desc" name="meta_desc" maxlength="300"
                      style="min-height:70px"
                      placeholder="Defaults to the excerpt"><?= e($f['meta_desc']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ sidebar ============ -->
    <div>
      <div class="adm-panel">
        <div class="adm-panel-head"><h2>Publish</h2></div>
        <div class="adm-panel-body">
          <div class="form-group">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
              <option value="draft" <?= $f['status'] === 'draft' ? 'selected' : '' ?>>Draft &mdash; hidden</option>
              <option value="published" <?= $f['status'] === 'published' ? 'selected' : '' ?>>Published &mdash; live</option>
            </select>
          </div>

          <div class="form-group">
            <label class="adm-switch">
              <input type="checkbox" name="is_featured" value="1" <?= $f['is_featured'] ? 'checked' : '' ?>>
              <span class="track"></span>
              <span>Feature on the homepage</span>
            </label>
          </div>

          <?php if ($post): ?>
            <div class="kv"><span class="k">Created</span><span class="v"><?= fdate($post['created_at'], 'd M Y') ?></span></div>
            <div class="kv"><span class="k">Published</span><span class="v"><?= fdate($post['published_at'], 'd M Y') ?></span></div>
            <div class="kv"><span class="k">Views</span><span class="v"><?= num($post['views']) ?></span></div>
          <?php endif; ?>

          <button class="btn btn-primary btn-block btn-lg mt-3" type="submit">
            <?= $post ? 'Save Changes' : 'Create Post' ?></button>

          <?php if ($post && $post['status'] === 'published'): ?>
            <a href="<?= url('post.php?slug=' . urlencode($post['slug'])) ?>" target="_blank"
               rel="noopener" class="btn btn-ghost btn-block mt-2">Preview live &nearr;</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="adm-panel">
        <div class="adm-panel-head"><h2>Cover image</h2></div>
        <div class="adm-panel-body">
          <div class="adm-thumb" id="coverBox">
            <?php if ($coverUrl): ?>
              <img src="<?= e($coverUrl) ?>" alt="" id="coverImg">
            <?php else: ?>
              <span class="ph">No cover set</span>
            <?php endif; ?>
          </div>
          <input class="form-control" type="file" name="cover" id="coverInput" accept="image/*">
          <div class="form-hint">JPG, PNG, GIF or WebP &middot; max 5&nbsp;MB &middot; 16:9 works best</div>
          <?php if ($coverUrl): ?>
            <label class="check mt-2">
              <input type="checkbox" name="remove_cover" value="1">
              <span>Remove the current cover on save</span>
            </label>
          <?php endif; ?>
        </div>
      </div>

      <div class="adm-panel">
        <div class="adm-panel-head"><h2>Organise</h2></div>
        <div class="adm-panel-body">
          <div class="form-group">
            <label class="form-label" for="category_id">Category</label>
            <select class="form-select" id="category_id" name="category_id">
              <option value="">Uncategorised</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int) $c['id'] ?>"
                  <?= (string) $f['category_id'] === (string) $c['id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint"><a href="<?= admin_url('categories.php') ?>">Manage categories</a></div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="tags">Tags</label>
            <input class="form-control" id="tags" name="tags" value="<?= e($f['tags']) ?>"
                   placeholder="sudoku, tips, strategy">
            <div class="form-hint">Comma separated</div>
          </div>
        </div>
      </div>
    </div>

  </div>
</form>

<?php
$admin_foot = <<<'HTML'
<script>
(function () {
  var editor  = document.getElementById('editor');
  var field   = document.getElementById('contentField');
  var form    = document.getElementById('postForm');
  var titleEl = document.getElementById('title');
  var slugEl  = document.getElementById('slug');
  var slugOut = document.getElementById('slugOut');
  var excEl   = document.getElementById('excerpt');
  var excCnt  = document.getElementById('excCount');
  var htmlMode = false;

  /* ---------- toolbar ---------- */
  document.getElementById('etb').addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-cmd]');
    if (!b) return;
    ev.preventDefault();
    editor.focus();
    document.execCommand(b.dataset.cmd, false, b.dataset.val || null);
    sync();
  });

  document.getElementById('btnLink').addEventListener('click', function () {
    var url = prompt('Link URL (include https://):', 'https://');
    if (!url) return;
    editor.focus();
    document.execCommand('createLink', false, url);
    sync();
  });
  document.getElementById('btnUnlink').addEventListener('click', function () {
    editor.focus(); document.execCommand('unlink', false, null); sync();
  });
  document.getElementById('btnImg').addEventListener('click', function () {
    var url = prompt('Image URL:', 'https://');
    if (!url) return;
    editor.focus();
    document.execCommand('insertImage', false, url);
    sync();
  });

  /* ---------- HTML source toggle ---------- */
  document.getElementById('btnHtml').addEventListener('click', function () {
    htmlMode = !htmlMode;
    if (htmlMode) {
      editor.textContent = editor.innerHTML;
      editor.style.fontFamily = 'ui-monospace, monospace';
      editor.style.fontSize = '.88rem';
      editor.style.whiteSpace = 'pre-wrap';
      this.textContent = 'Back to Editor';
    } else {
      editor.innerHTML = editor.textContent;
      editor.style.fontFamily = '';
      editor.style.fontSize = '';
      editor.style.whiteSpace = '';
      this.textContent = 'Toggle HTML';
    }
    sync();
  });

  /* ---------- paste as clean-ish HTML ---------- */
  editor.addEventListener('paste', function (ev) {
    var html = (ev.clipboardData || window.clipboardData).getData('text/html');
    if (!html) return;
    ev.preventDefault();
    html = html.replace(/<!--[\s\S]*?-->/g, '')
               .replace(/<\/?(?:span|font|meta|style|script|o:p)[^>]*>/gi, '')
               .replace(/\s(?:class|style|lang|dir)="[^"]*"/gi, '');
    document.execCommand('insertHTML', false, html);
    sync();
  });

  /* ---------- keep the hidden field + counters in sync ---------- */
  function sync() {
    field.value = htmlMode ? editor.textContent : editor.innerHTML;
    var text = (editor.textContent || '').trim();
    var words = text ? text.split(/\s+/).length : 0;
    document.getElementById('wordCount').textContent = words;
    document.getElementById('readTime').textContent = Math.max(1, Math.round(words / 210));
    try { localStorage.setItem('asv_draft_' + (form.dataset.pid || 'new'), field.value); } catch (e) {}
  }
  editor.addEventListener('input', sync);
  editor.addEventListener('blur', sync);
  form.addEventListener('submit', sync);
  sync();

  /* ---------- slug auto-fill ---------- */
  function slugify(s) {
    return s.toLowerCase().trim()
      .replace(/[^\w\s-]/g, '')
      .replace(/[\s_-]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }
  var slugTouched = slugEl.value !== '';
  slugEl.addEventListener('input', function () { slugTouched = true; paintSlug(); });
  titleEl.addEventListener('input', function () {
    if (!slugTouched) slugEl.value = slugify(titleEl.value);
    paintSlug();
  });
  function paintSlug() { slugOut.textContent = slugify(slugEl.value || titleEl.value) || 'your-post'; }
  paintSlug();

  /* ---------- excerpt counter ---------- */
  if (excEl) {
    excEl.addEventListener('input', function () { excCnt.textContent = excEl.value.length; });
  }

  /* ---------- cover preview ---------- */
  var ci = document.getElementById('coverInput');
  ci.addEventListener('change', function () {
    var f = ci.files && ci.files[0];
    if (!f) return;
    var box = document.getElementById('coverBox');
    var url = URL.createObjectURL(f);
    box.innerHTML = '<img src="' + url + '" alt="">';
  });

  /* ---------- Ctrl+S saves ---------- */
  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
      ev.preventDefault();
      sync();
      form.submit();
    }
  });
})();
</script>
HTML;

require __DIR__ . '/includes/layout_end.php';
