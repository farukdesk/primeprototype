package bd.ac.primeuniversity.studentportal.ui.support

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.OpenableColumns
import android.view.View
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.text.HtmlCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketDetailResponse
import bd.ac.primeuniversity.studentportal.data.model.TicketAttachment
import bd.ac.primeuniversity.studentportal.databinding.ActivitySupportTicketDetailBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.android.material.chip.Chip
import kotlinx.coroutines.launch

/**
 * Full IT support ticket thread: description, attachments, the public
 * comment history, and a composer so the student can reply (optionally
 * attaching files).
 */
class SupportTicketDetailActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySupportTicketDetailBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val commentsAdapter = TicketCommentAdapter { openAttachment(it) }
    private var ticketId = 0
    private val pendingAttachments = mutableListOf<Uri>()

    private val pickFiles =
        registerForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
            if (!uris.isNullOrEmpty()) {
                pendingAttachments.addAll(uris)
                renderPendingChips()
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySupportTicketDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        ticketId = intent.getIntExtra(EXTRA_TICKET_ID, 0)
        if (ticketId <= 0) {
            finish()
            return
        }

        binding.btnBack.setOnClickListener { finish() }

        binding.commentsList.layoutManager = LinearLayoutManager(this)
        binding.commentsList.adapter = commentsAdapter
        binding.commentsList.isNestedScrollingEnabled = false

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info
        )
        binding.swipeRefresh.setOnRefreshListener { load() }

        binding.btnAttach.setOnClickListener { pickFiles.launch(SUPPORTED_TYPES) }
        binding.btnPost.setOnClickListener { postComment() }

        load(initial = true)
    }

    private fun load(initial: Boolean = false) {
        if (initial) binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            when (val result = app.repository.getSupportTicketDetail(ticketId)) {
                is AppResult.Success -> bind(result.data)
                is AppResult.Error ->
                    Toast.makeText(this@SupportTicketDetailActivity, result.message, Toast.LENGTH_LONG)
                        .show()
            }
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
        }
    }

    private fun bind(data: SupportTicketDetailResponse) {
        val t = data.ticket ?: return
        binding.content.visibility = View.VISIBLE
        binding.tvTicketNumber.text = t.ticketNumber
        binding.tvTitle.text = t.title
        binding.tvMeta.text = getString(R.string.support_detail_status, t.status, t.priority) +
            "\n" + getString(R.string.support_detail_category, t.category)
        binding.tvDates.text = buildString {
            append(getString(R.string.support_detail_created, t.date))
            t.deadline?.let {
                append('\n')
                append(getString(R.string.support_deadline, it))
            }
        }
        binding.tvDescription.text =
            HtmlCompat.fromHtml(t.description, HtmlCompat.FROM_HTML_MODE_LEGACY).toString().trim()

        binding.ticketAttachments.removeAllViews()
        val hasAttachments = data.attachments.isNotEmpty()
        binding.attachmentsLabel.visibility = if (hasAttachments) View.VISIBLE else View.GONE
        binding.ticketAttachments.visibility = if (hasAttachments) View.VISIBLE else View.GONE
        data.attachments.forEach { att ->
            binding.ticketAttachments.addView(Chip(this).apply {
                text = att.name
                setOnClickListener { openAttachment(att) }
            })
        }

        binding.commentsLabel.text = getString(R.string.support_comments_count, data.comments.size)
        commentsAdapter.submitList(data.comments)
        binding.emptyComments.visibility =
            if (data.comments.isEmpty()) View.VISIBLE else View.GONE
    }

    private fun postComment() {
        val text = binding.inputComment.text?.toString()?.trim().orEmpty()
        if (text.isEmpty()) {
            Toast.makeText(this, R.string.support_err_comment, Toast.LENGTH_SHORT).show()
            return
        }
        binding.btnPost.isEnabled = false
        lifecycleScope.launch {
            val result = app.repository.addSupportTicketComment(
                ticketId, text, pendingAttachments.toList()
            )
            when (result) {
                is AppResult.Success -> {
                    binding.inputComment.setText("")
                    pendingAttachments.clear()
                    renderPendingChips()
                    Toast.makeText(
                        this@SupportTicketDetailActivity,
                        R.string.support_comment_posted,
                        Toast.LENGTH_SHORT,
                    ).show()
                    load()
                }
                is AppResult.Error ->
                    Toast.makeText(this@SupportTicketDetailActivity, result.message, Toast.LENGTH_LONG)
                        .show()
            }
            binding.btnPost.isEnabled = true
        }
    }

    private fun renderPendingChips() {
        binding.pendingChips.removeAllViews()
        binding.pendingChips.visibility =
            if (pendingAttachments.isEmpty()) View.GONE else View.VISIBLE
        pendingAttachments.forEach { uri ->
            binding.pendingChips.addView(Chip(this).apply {
                text = displayName(uri)
                isCloseIconVisible = true
                setOnCloseIconClickListener {
                    pendingAttachments.remove(uri)
                    renderPendingChips()
                }
            })
        }
    }

    private fun displayName(uri: Uri): String =
        try {
            contentResolver.query(uri, null, null, null, null)?.use { c ->
                val idx = c.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                if (c.moveToFirst() && idx >= 0) c.getString(idx) else null
            } ?: uri.lastPathSegment ?: "attachment"
        } catch (_: Exception) {
            uri.lastPathSegment ?: "attachment"
        }

    private fun openAttachment(att: TicketAttachment) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(att.url)))
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(this, R.string.support_open_attachment_error, Toast.LENGTH_SHORT).show()
        }
    }

    companion object {
        const val EXTRA_TICKET_ID = "ticket_id"

        /** Attachment types accepted by the server (see the student API). */
        val SUPPORTED_TYPES = arrayOf(
            "image/*",
            "application/pdf",
            "application/msword",
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "application/vnd.ms-excel",
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "application/zip",
            "text/plain",
        )
    }
}
