package bd.ac.primeuniversity.studentportal.ui.idcard

import android.graphics.Bitmap
import android.graphics.Color
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.databinding.ActivityIdCardBinding
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel

/** Digital student ID card with a scannable QR code encoding a verification URL. */
class IdCardActivity : AppCompatActivity() {

    private lateinit var binding: ActivityIdCardBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityIdCardBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }

        val student = (application as PrimeApp).currentStudent.value
        if (student != null) render(student) else finish()
    }

    private fun render(student: Student) {
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
}
