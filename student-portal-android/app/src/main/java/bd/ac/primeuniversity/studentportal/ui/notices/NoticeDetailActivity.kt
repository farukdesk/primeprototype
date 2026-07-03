package bd.ac.primeuniversity.studentportal.ui.notices

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.text.HtmlCompat
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.Notice
import bd.ac.primeuniversity.studentportal.databinding.ActivityNoticeDetailBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/** Full notice view with rendered content and an optional attachment link. */
class NoticeDetailActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNoticeDetailBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNoticeDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        val id = intent.getIntExtra(EXTRA_ID, 0)
        val type = intent.getStringExtra(EXTRA_TYPE) ?: "university"
        loadDetail(id, type)
    }

    private fun loadDetail(id: Int, type: String) {
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            when (val result = app.repository.getNoticeDetail(id, type)) {
                is AppResult.Success -> {
                    binding.progress.visibility = View.GONE
                    bind(result.data)
                }
                is AppResult.Error -> {
                    binding.progress.visibility = View.GONE
                    binding.content.text = result.message
                }
            }
        }
    }

    private fun bind(notice: Notice) {
        val accent = ContextCompat.getColor(
            this, if (notice.isDepartment) R.color.success else R.color.primary
        )
        binding.typeTag.text = if (notice.isDepartment)
            (notice.deptName ?: getString(R.string.seg_department))
        else getString(R.string.label_university)
        binding.typeTag.setTextColor(accent)
        binding.date.text = notice.date
        binding.title.text = notice.title

        val body = notice.content.orEmpty()
        binding.content.text = if (notice.contentType == "html") {
            HtmlCompat.fromHtml(body, HtmlCompat.FROM_HTML_MODE_COMPACT)
        } else {
            body
        }

        if (notice.hasAttachment) {
            binding.attachmentCard.visibility = View.VISIBLE
            binding.attachmentName.text =
                notice.attachmentName ?: getString(R.string.download_attachment)
            binding.attachmentSize.visibility =
                if (notice.attachmentSizeKb != null) View.VISIBLE else View.GONE
            binding.attachmentSize.text = notice.attachmentSizeKb?.let { "$it KB" }
            binding.attachmentCard.setOnClickListener {
                openUrl(notice.attachmentUrl)
            }
        } else {
            binding.attachmentCard.visibility = View.GONE
        }
    }

    private fun openUrl(url: String?) {
        if (url.isNullOrEmpty()) return
        try {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
        } catch (_: Exception) {
            // No app able to open the link; ignore silently.
        }
    }

    companion object {
        private const val EXTRA_ID = "notice_id"
        private const val EXTRA_TYPE = "notice_type"

        fun intent(context: Context, id: Int, type: String): Intent =
            Intent(context, NoticeDetailActivity::class.java).apply {
                putExtra(EXTRA_ID, id)
                putExtra(EXTRA_TYPE, type)
            }
    }
}
