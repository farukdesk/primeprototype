package bd.ac.primeuniversity.studentportal.ui.support

import android.net.Uri
import android.os.Bundle
import android.provider.OpenableColumns
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivitySupportTicketCreateBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.android.material.chip.Chip
import kotlinx.coroutines.launch

/**
 * New IT support ticket form. Students provide a title, category,
 * description and optional file attachments; priority and the SLA
 * deadline are set by the server.
 */
class SupportTicketCreateActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySupportTicketCreateBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val categories = listOf(
        "Student Finances",
        "Other Student Issues",
        "Hardware",
        "Software",
        "Network",
        "Email",
        "Other",
    )

    private val attachments = mutableListOf<Uri>()

    private val pickFiles =
        registerForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
            if (!uris.isNullOrEmpty()) {
                attachments.addAll(uris)
                renderAttachmentChips()
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySupportTicketCreateBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.categorySpinner.adapter = ArrayAdapter(
            this, android.R.layout.simple_spinner_dropdown_item, categories
        )

        binding.btnAttach.setOnClickListener {
            pickFiles.launch(SupportTicketDetailActivity.SUPPORTED_TYPES)
        }
        binding.btnSubmit.setOnClickListener { submit() }
    }

    private fun renderAttachmentChips() {
        binding.attachmentChips.removeAllViews()
        binding.attachmentChips.visibility =
            if (attachments.isEmpty()) View.GONE else View.VISIBLE
        attachments.forEach { uri ->
            binding.attachmentChips.addView(Chip(this).apply {
                text = displayName(uri)
                isCloseIconVisible = true
                setOnCloseIconClickListener {
                    attachments.remove(uri)
                    renderAttachmentChips()
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

    private fun submit() {
        val title = binding.inputTitle.text?.toString()?.trim().orEmpty()
        val description = binding.inputDescription.text?.toString()?.trim().orEmpty()

        binding.titleLayout.error = null
        binding.descriptionLayout.error = null

        if (title.isEmpty()) {
            binding.titleLayout.error = getString(R.string.support_err_title)
            return
        }
        if (description.isEmpty()) {
            binding.descriptionLayout.error = getString(R.string.support_err_description)
            return
        }

        val category = categories[binding.categorySpinner.selectedItemPosition]

        binding.btnSubmit.isEnabled = false
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            val result = app.repository.createSupportTicket(
                title, description, category, attachments.toList()
            )
            when (result) {
                is AppResult.Success -> {
                    Toast.makeText(
                        this@SupportTicketCreateActivity,
                        result.data.message ?: getString(R.string.support_submitted),
                        Toast.LENGTH_LONG,
                    ).show()
                    finish()
                    return@launch
                }
                is AppResult.Error -> {
                    Toast.makeText(this@SupportTicketCreateActivity, result.message, Toast.LENGTH_LONG)
                        .show()
                    binding.btnSubmit.isEnabled = true
                    binding.progress.visibility = View.GONE
                }
            }
        }
    }
}
