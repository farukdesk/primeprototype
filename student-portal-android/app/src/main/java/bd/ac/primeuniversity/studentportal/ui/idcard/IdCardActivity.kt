package bd.ac.primeuniversity.studentportal.ui.idcard

import android.graphics.Bitmap
import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.webkit.WebView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.DigitalIdCard
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.databinding.ActivityIdCardBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel
import kotlinx.coroutines.launch

/**
 * Digital student ID card.
 *
 * When the university has generated an official ID card for the student
 * (admin ID Card module), the real card design (front + back) is shown
 * exactly as printed. When no card exists yet, the app falls back to the
 * generated verification card with a scannable QR code.
 */
class IdCardActivity : AppCompatActivity() {

    private lateinit var binding: ActivityIdCardBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityIdCardBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }

        val student = app.currentStudent.value
        if (student == null) {
            finish()
            return
        }
        load(student)
    }

    /** Fetches the official ID card; falls back to the QR card when none exists. */
    private fun load(student: Student) {
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            val result = app.repository.getIdCard()
            binding.progress.visibility = View.GONE
            val card = (result as? AppResult.Success)
                ?.data
                ?.takeIf { it.hasCard }
                ?.card
            if (card != null && !card.frontSvg.isNullOrBlank()) {
                showOfficialCard(card)
            } else {
                renderFallback(student)
            }
        }
    }

    // ── Official ID card (server-rendered SVG, same design as the printed card) ──

    private fun showOfficialCard(card: DigitalIdCard) {
        binding.officialContainer.visibility = View.VISIBLE
        binding.fallbackCard.visibility = View.GONE

        loadCardSide(binding.frontWebView, card.frontSvg!!)

        val back = card.backSvg
        if (!back.isNullOrBlank()) {
            loadCardSide(binding.backWebView, back)
        } else {
            binding.backCard.visibility = View.GONE
        }

        val expiry = card.expiryDate?.takeIf { it.isNotBlank() }
        binding.cardValidity.text =
            if (expiry != null) getString(R.string.id_card_valid_until, expiry)
            else getString(R.string.id_card_valid)
    }

    /** Renders one card side (SVG) in a WebView, keeping the printed aspect ratio. */
    private fun loadCardSide(webView: WebView, svg: String) {
        webView.setBackgroundColor(Color.TRANSPARENT)
        webView.isVerticalScrollBarEnabled = false
        webView.isHorizontalScrollBarEnabled = false
        webView.settings.loadWithOverviewMode = true
        webView.settings.useWideViewPort = true

        // Match the printed card's 331.2 x 212.16 aspect ratio.
        webView.post {
            val width = webView.width
            if (width > 0) {
                val params = webView.layoutParams
                params.height = (width * CARD_ASPECT).toInt()
                webView.layoutParams = params
            }
        }

        val html = """
            <!DOCTYPE html><html><head>
            <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
            <style>
              html,body{margin:0;padding:0;background:transparent;overflow:hidden}
              svg{display:block;width:100vw;height:auto}
            </style>
            </head><body>$svg</body></html>
        """.trimIndent()
        webView.loadDataWithBaseURL(null, html, "text/html", "utf-8", null)
    }

    // ── Fallback: app-generated verification card with QR code ──────────────────

    private fun renderFallback(student: Student) {
        binding.fallbackCard.visibility = View.VISIBLE
        binding.officialContainer.visibility = View.GONE

        binding.avatar.text = student.initials
        binding.idName.text = student.fullName ?: getString(R.string.student)
        binding.idNumber.text = student.studentId ?: getString(R.string.dash)

        infoRow(binding.rowDept, R.string.label_department, student.deptName)
        infoRow(binding.rowProgram, R.string.label_program, student.programName)
        infoRow(binding.rowBatch, R.string.label_batch, student.batchName)
        infoRow(binding.rowStatus, R.string.label_status, student.status)

        binding.qrImage.setImageBitmap(generateQr(verificationPayload(student)))
    }

    private fun infoRow(
        row: bd.ac.primeuniversity.studentportal.databinding.ItemInfoRowBinding,
        labelRes: Int,
        value: String?,
    ) {
        row.rowLabel.setText(labelRes)
        val text = value?.takeIf { it.isNotBlank() }
        row.rowValue.text = text ?: getString(R.string.dash)
    }

    private fun verificationPayload(student: Student): String {
        val id = student.studentId?.takeIf { it.isNotBlank() } ?: student.id.toString()
        return "https://primeuniversity.ac.bd/verify?sid=" +
            java.net.URLEncoder.encode(id, "UTF-8")
    }

    private fun generateQr(content: String, size: Int = 512): Bitmap {
        val hints = mapOf(
            EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M,
            EncodeHintType.MARGIN to 1,
        )
        val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, size, size, hints)
        val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.RGB_565)
        for (x in 0 until size) {
            for (y in 0 until size) {
                bitmap.setPixel(x, y, if (matrix[x, y]) Color.BLACK else Color.WHITE)
            }
        }
        return bitmap
    }

    companion object {
        /** Height / width of the printed card design (212.16 / 331.2). */
        private const val CARD_ASPECT = 212.16f / 331.2f
    }
}
